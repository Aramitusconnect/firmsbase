<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsPushSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\SimulatedProviderFailureException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Jobs\SyncRetryPollJob;
use App\Models\Firm;
use App\Services\WebhookRetryPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SyncRetryPollJobExhaustionTest — Checkpoint 13 P2 (reconciliation #2,
 * FIX_BEFORE_FINAL_APPROVAL — agent-13h-testing-release-review.md §2/§4
 * item 1; frozen-test-closure-plan.md §4).
 *
 * Proves the new retry-exhaustion ceiling in SyncRetryPollJob's inline
 * single-item re-push path: a push-shaped item whose provider push
 * repeatedly fails with a non-terminal category (which, before this fix,
 * was put back as FailedRetryable every poll cycle FOREVER — attempt_count
 * was incremented but never read) now transitions to FailedPermanent once
 * the attempt-count ceiling (SyncRetryPollJob::DEFAULT_MAX_ATTEMPTS = 5,
 * equal to WebhookRetryPolicyService::DEFAULT_RETRY_POLICY['max_attempts'])
 * is reached, and is then never re-claimed by a subsequent poll cycle.
 *
 * Ceiling mechanics (read from the diff): the retryable-failure branch
 * calls WebhookRetryPolicyService::isExhausted($item->attempt_count + 1,
 * ['max_attempts' => 5]). $item->attempt_count is the PRE-resolution value
 * of the row just claimed; resolveRetryOutcome() then increments it by 1.
 * So exhaustion (FailedPermanent) fires exactly when attempt_count + 1 >= 5,
 * i.e. once the row's attempt_count has reached 4 at claim time. The final
 * last_error is honest — "retry_exhausted_after_max_attempts: {category}"
 * — preserving the real final provider category, never a fabricated
 * provider-side reason.
 *
 * A pure single-connection job test (no concurrency), so RefreshDatabase
 * is appropriate here (the two genuine-two-physical-connection race proofs
 * live in the sibling SyncRetryPollJobTest / IntegrationOutbox... files).
 */
final class SyncRetryPollJobExhaustionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
    }

    private function firm(): Firm
    {
        return Firm::factory()->create();
    }

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Active->value, 'external_account_id' => null]),
        );
    }

    private function terminalRun(Firm $firm, FirmIntegration $connection): IntegrationSyncRun
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create());
    }

    /**
     * A push-shaped failed_retryable item at a chosen starting
     * attempt_count, due now (next_attempt_at in the past).
     */
    private function pushItem(Firm $firm, IntegrationSyncRun $run, int $attemptCount): IntegrationSyncItem
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create([
                'next_attempt_at' => now()->subMinute(),
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 4242,
                'attempt_count' => $attemptCount,
            ]));
    }

    private function registerAlwaysFailingPushProvider(string $failCategory = 'rate_limited'): void
    {
        $provider = new class($failCategory) implements IntegrationProviderContract, SupportsPushSyncContract {
            public function __construct(private readonly string $failCategory)
            {
            }

            public function key(): ProviderKey
            {
                return ProviderKey::Test;
            }

            public function displayName(): string
            {
                return 'Always-Failing Retry Push Provider';
            }

            public function description(): string
            {
                return 'Deterministic test fixture provider that always fails push().';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function supportedAuthMethods(): array
            {
                return [AuthMethod::None];
            }

            public function pushableResourceTypes(): array
            {
                return ['contact'];
            }

            public function push(array $context, string $resourceType, array $payload): array
            {
                throw new SimulatedProviderFailureException($this->failCategory, null, 'Simulated fixture failure.');
            }
        };

        $class = get_class($provider);
        app()->instance($class, $provider);
        config(['integrations.providers' => [ProviderKey::Test->value => $class]]);
    }

    private function runJob(Firm $firm): void
    {
        $job = new SyncRetryPollJob($firm->id, 25);
        $job->handle(
            app(SyncItemService::class),
            app(IntegrationExternalMappingService::class),
            app(ProviderRegistry::class),
            app(OutboundProviderHttpClient::class),
            app(HealthStateService::class),
        );
    }

    private function itemRow(Firm $firm, int $itemId): object
    {
        return $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_items')->where('id', $itemId)->first());
    }

    /**
     * Force the item due again (a failed retry sets next_attempt_at into
     * the future) so the next poll cycle will re-claim it — modelling the
     * scheduler coming back around after the backoff window.
     */
    private function forceDue(Firm $firm, int $itemId): void
    {
        $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_items')
            ->where('id', $itemId)
            ->update(['next_attempt_at' => now()->subMinute()]));
    }

    // ------------------------------------------------------------
    // The ceiling equals WebhookRetryPolicyService's shared default —
    // pin it so a silent divergence is caught.
    // ------------------------------------------------------------

    public function test_the_exhaustion_ceiling_equals_the_shared_webhook_retry_policy_default(): void
    {
        $this->assertSame(
            5,
            WebhookRetryPolicyService::DEFAULT_RETRY_POLICY['max_attempts'],
            'This test suite assumes the ceiling is 5 (SyncRetryPollJob::DEFAULT_MAX_ATTEMPTS mirrors WebhookRetryPolicyService::DEFAULT_RETRY_POLICY[max_attempts]).'
        );
    }

    // ------------------------------------------------------------
    // Repeated real poll cycles drive a permanently-failing item to
    // FailedPermanent exactly at the ceiling — never one cycle early, and
    // never "retryable forever."
    // ------------------------------------------------------------

    public function test_a_repeatedly_failing_push_item_becomes_failed_permanent_exactly_at_the_attempt_ceiling(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);
        $item = $this->pushItem($firm, $run, attemptCount: 0);

        $this->registerAlwaysFailingPushProvider('rate_limited');

        // attempt_count starts at 0; each failing cycle increments it.
        // Ceiling fires when attempt_count reaches 4 at claim time
        // (4 + 1 >= 5). So cycles at attempt_count 0,1,2,3 stay
        // FailedRetryable and the 5th cycle (attempt_count 4) is permanent.
        $cyclesUntilPermanent = 0;

        for ($i = 0; $i < 10; $i++) {
            $this->forceDue($firm, $item->id);
            $this->runJob($firm);
            $cyclesUntilPermanent++;

            $fresh = $this->itemRow($firm, $item->id);

            if ($fresh->status === 'failed_permanent') {
                break;
            }

            $this->assertSame(
                'failed_retryable',
                $fresh->status,
                "Before the ceiling, cycle {$cyclesUntilPermanent} must leave the item FailedRetryable, never permanently failed."
            );
        }

        $final = $this->itemRow($firm, $item->id);

        $this->assertSame('failed_permanent', $final->status, 'A repeatedly-failing push item must eventually become FailedPermanent, not retry forever.');
        $this->assertSame(5, $cyclesUntilPermanent, 'FailedPermanent must fire exactly on the 5th failing cycle (attempt_count 4 -> 4+1 >= 5), never earlier.');
        $this->assertSame(5, (int) $final->attempt_count, 'The final attempt_count must be exactly 5.');
        $this->assertSame(
            'retry_exhausted_after_max_attempts: rate_limited',
            $final->last_error,
            'The exhaustion last_error must be honest — it must preserve the real final provider category (rate_limited), never a fabricated reason.'
        );
    }

    // ------------------------------------------------------------
    // One below the ceiling — still FailedRetryable, proving the ceiling
    // is exact, not "any repeated failure permanently fails."
    // ------------------------------------------------------------

    public function test_the_cycle_one_below_the_ceiling_is_still_failed_retryable(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);
        // attempt_count = 3 -> 3 + 1 = 4 < 5 -> still retryable.
        $item = $this->pushItem($firm, $run, attemptCount: 3);

        $this->registerAlwaysFailingPushProvider('rate_limited');
        $this->runJob($firm);

        $fresh = $this->itemRow($firm, $item->id);
        $this->assertSame('failed_retryable', $fresh->status, 'attempt_count 3 (3+1=4 < 5) is one below the ceiling — the item must be put back FailedRetryable, not permanently failed.');
        $this->assertSame(4, (int) $fresh->attempt_count);
        $this->assertStringContainsString('retry_push_failed: rate_limited', $fresh->last_error, 'A non-exhausting failure keeps the ordinary retryable last_error, not the exhaustion one.');
    }

    // ------------------------------------------------------------
    // At the ceiling in a single cycle — FailedPermanent immediately.
    // ------------------------------------------------------------

    public function test_an_item_already_at_the_ceiling_becomes_failed_permanent_in_a_single_cycle(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);
        // attempt_count = 4 -> 4 + 1 = 5 >= 5 -> permanent this cycle.
        $item = $this->pushItem($firm, $run, attemptCount: 4);

        $this->registerAlwaysFailingPushProvider('timeout');
        $this->runJob($firm);

        $fresh = $this->itemRow($firm, $item->id);
        $this->assertSame('failed_permanent', $fresh->status);
        $this->assertSame('retry_exhausted_after_max_attempts: timeout', $fresh->last_error, 'The honest last_error must preserve whatever the real final category was — here, timeout.');
        $this->assertNotNull($fresh->terminal_at, 'A FailedPermanent resolution must stamp terminal_at.');
    }

    // ------------------------------------------------------------
    // Once permanently failed, subsequent poll cycles never touch it.
    // ------------------------------------------------------------

    public function test_a_failed_permanent_item_is_never_reclaimed_by_a_subsequent_poll_cycle(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);
        $item = $this->pushItem($firm, $run, attemptCount: 4);

        $this->registerAlwaysFailingPushProvider('rate_limited');
        $this->runJob($firm);

        $afterExhaustion = $this->itemRow($firm, $item->id);
        $this->assertSame('failed_permanent', $afterExhaustion->status);

        // Even if some later process makes the row "due" again, a
        // FailedPermanent row is not status='failed_retryable', so
        // claimForRetry()'s WHERE clause can never re-select it.
        $this->forceDue($firm, $item->id);
        $this->runJob($firm);
        $this->runJob($firm);

        $stillTerminal = $this->itemRow($firm, $item->id);
        $this->assertSame('failed_permanent', $stillTerminal->status, 'A FailedPermanent item must remain terminal — never re-claimed by a later poll cycle.');
        $this->assertSame(
            (int) $afterExhaustion->attempt_count,
            (int) $stillTerminal->attempt_count,
            'attempt_count must not advance further once the item is FailedPermanent — proving it was genuinely not re-claimed/resolved again.'
        );
    }
}
