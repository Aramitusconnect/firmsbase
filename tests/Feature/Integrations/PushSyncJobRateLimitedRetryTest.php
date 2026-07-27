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
use App\Integrations\Services\IntegrationConflictService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Services\SyncRunService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Jobs\PushSyncJob;
use App\Models\Firm;
use App\Services\WebhookRetryPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PushSyncJobRateLimitedRetryTest — Checkpoint 1 (FirmsVault Live
 * Integrations), proves the checkpoint1-design-http-ratelimit-usage.md
 * §4.4 fix to App\Jobs\PushSyncJob::handle(): a SanitizedProviderHttpException
 * in a NON-TERMINAL category (per App\Services\WebhookRetryPolicyService::TERMINAL_CATEGORIES)
 * must produce a `failed_retryable` sync item with a future
 * `next_attempt_at`, never a permanent failure — the regression this
 * checkpoint's rate-limiter wiring would otherwise introduce (a
 * merely-rate-limited connection being permanently failed instead of
 * retried). A second, terminal-category scenario proves the
 * PRE-EXISTING `failed_permanent` behavior for genuinely terminal
 * categories is unchanged.
 *
 * Uses a deterministic fake push provider (same double pattern as
 * PushSyncJobTest::registerFakePushProvider()) rather than genuine
 * TestProvider, so the exact SanitizedProviderHttpException category can
 * be controlled directly — genuine TestProvider::push() only exposes
 * `provider_rejected` and `rate_limited` sentinels, neither of which is
 * terminal, so a genuinely terminal category could not otherwise be
 * exercised through a real push() call.
 */
final class PushSyncJobRateLimitedRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_terminal_category_failure_produces_a_failed_retryable_item_with_a_future_next_attempt_at(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        // rate_limited is confirmed NOT in WebhookRetryPolicyService::TERMINAL_CATEGORIES.
        $this->assertNotContains('rate_limited', WebhookRetryPolicyService::TERMINAL_CATEGORIES);

        $this->registerFakePushProvider(failureCategory: 'rate_limited', retryAfterRaw: '90');

        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 1001, 'local-v1');

        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::query()
            ->where('firm_id', $firm->id)
            ->where('local_id', 1001)
            ->first());

        $this->assertNotNull($item);
        $this->assertSame('failed_retryable', $item->status->value, 'A non-terminal (rate_limited) category must produce FailedRetryable, never FailedPermanent.');
        $this->assertNotNull($item->next_attempt_at, 'A retryable item must carry a next_attempt_at.');
        $this->assertTrue($item->next_attempt_at->isFuture(), 'next_attempt_at must be scheduled in the future.');
        $this->assertNull($item->terminal_at, 'A retryable item must not be marked terminal.');

        $run = $this->latestRun($firm, $connection);
        $this->assertSame('partial_failure', $run->status);
    }

    public function test_a_terminal_category_failure_still_produces_a_failed_permanent_item_regression_check(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        // authentication_failed IS in WebhookRetryPolicyService::TERMINAL_CATEGORIES.
        $this->assertContains('authentication_failed', WebhookRetryPolicyService::TERMINAL_CATEGORIES);

        $this->registerFakePushProvider(failureCategory: 'authentication_failed');

        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 1002, 'local-v1');

        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::query()
            ->where('firm_id', $firm->id)
            ->where('local_id', 1002)
            ->first());

        $this->assertNotNull($item);
        $this->assertSame('failed_permanent', $item->status->value, 'A terminal (authentication_failed) category must still produce FailedPermanent — pre-existing behavior must be unchanged.');
        $this->assertNotNull($item->terminal_at, 'A permanently failed item must be marked terminal.');

        $run = $this->latestRun($firm, $connection);
        $this->assertSame('failed', $run->status);
    }

    // ------------------------------------------------------------
    // Helpers (mirrors PushSyncJobTest's own established pattern)
    // ------------------------------------------------------------

    private function connection(Firm $firm, ConnectionStatus $status = ConnectionStatus::Active): FirmIntegration
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['status' => $status->value, 'external_account_id' => null]),
        );
    }

    private function registerFakePushProvider(string $failureCategory, ?string $retryAfterRaw = null): void
    {
        $provider = new class($failureCategory, $retryAfterRaw) implements IntegrationProviderContract, SupportsPushSyncContract
        {
            public function __construct(
                private readonly string $failureCategory,
                private readonly ?string $retryAfterRaw,
            ) {}

            public function key(): ProviderKey
            {
                return ProviderKey::Test;
            }

            public function displayName(): string
            {
                return 'Fake Retry-Classification Provider';
            }

            public function description(): string
            {
                return 'Deterministic test fixture provider for retry-classification proof.';
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
                throw new SimulatedProviderFailureException(
                    category: $this->failureCategory,
                    statusCode: null,
                    message: 'Simulated fixture failure for retry-classification proof.',
                    retryAfterRaw: $this->retryAfterRaw,
                );
            }
        };

        $class = get_class($provider);
        app()->instance($class, $provider);
        config(['integrations.providers' => [ProviderKey::Test->value => $class]]);
    }

    private function dispatchPush(FirmIntegration $connection, int $firmId, string $localType, int $localId, string $localVersionToken): void
    {
        $job = new PushSyncJob($connection->id, $firmId, 'contact', $localType, $localId, $localVersionToken);
        $job->handle(
            app(SyncRunService::class),
            app(SyncItemService::class),
            app(IntegrationExternalMappingService::class),
            app(IntegrationConflictService::class),
            app(ProviderRegistry::class),
            app(OutboundProviderHttpClient::class),
        );
    }

    private function latestRun(Firm $firm, FirmIntegration $connection): ?object
    {
        return $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_runs')
            ->where('firm_integration_id', $connection->id)
            ->orderByDesc('id')
            ->first());
    }
}
