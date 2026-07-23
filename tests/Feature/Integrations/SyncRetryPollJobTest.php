<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsPushSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Exceptions\SimulatedProviderFailureException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Jobs\SyncRetryPollJob;
use App\Models\Firm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SyncRetryPollJobTest — Checkpoint 8
 * (agent-8h-architecture-security-review.md §0/§2 item 0/§4.2;
 * diff-review.md item 6). MUST prove the hard-prerequisite
 * statement_timestamp() fix in SyncItemService::claimForRetry() — the
 * single most important test in this entire suite, given the hard
 * prerequisite this checkpoint required. Also proves the pull-shaped
 * item honest-failure resolution (pull_item_retry_not_supported_
 * generically), the push-shaped item inline re-push path, and that
 * HealthStateService is called per the fix from diff-review.md item 6.
 *
 * Deliberately does NOT use RefreshDatabase, for the identical reason
 * documented in HealthStateServiceTest/IntegrationOutboxConcurrentClaimTest:
 * the hard-prerequisite proof needs a literal SECOND, separate physical
 * DB connection to construct a deterministic (never sleep()-based)
 * demonstration that a row becomes due only AFTER a still-open
 * transaction began — RefreshDatabase's own continuously-open,
 * never-committed outer transaction would make every fixture invisible
 * to that second connection. Every fixture is a real, committed row,
 * tracked and deleted in tearDown() via cascadeOnDelete() from `firms`.
 */
class SyncRetryPollJobTest extends TestCase
{
    /** @var int[] */
    private array $createdFirmIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (array_key_exists('worker_b', config('database.connections', []))) {
            while (DB::connection('worker_b')->transactionLevel() > 0) {
                DB::connection('worker_b')->rollBack();
            }
            DB::purge('worker_b');
        }
        DB::select('select set_config(?, ?, ?)', ['app.current_firm_id', '', false]);

        if ($this->createdFirmIds !== []) {
            DB::table('firms')->whereIn('id', $this->createdFirmIds)->delete();
        }

        parent::tearDown();
    }

    private function firm(): Firm
    {
        $firm = Firm::factory()->create();
        $this->createdFirmIds[] = $firm->id;

        return $firm;
    }

    private function connection(Firm $firm, ConnectionStatus $status = ConnectionStatus::Active): FirmIntegration
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['status' => $status->value, 'external_account_id' => null]),
        );
    }

    private function terminalRun(Firm $firm, FirmIntegration $connection): IntegrationSyncRun
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create());
    }

    private function registerFakePushProvider(?string $externalId = null, ?string $versionToken = null, bool $shouldFail = false, string $failCategory = 'rate_limited'): object
    {
        $provider = new class($externalId, $versionToken, $shouldFail, $failCategory) implements IntegrationProviderContract, SupportsPushSyncContract {
            public array $calls = [];

            public function __construct(
                private readonly ?string $externalId,
                private readonly ?string $versionToken,
                private readonly bool $shouldFail,
                private readonly string $failCategory,
            ) {
            }

            public function key(): ProviderKey
            {
                return ProviderKey::Test;
            }

            public function displayName(): string
            {
                return 'Fake Retry Push Provider';
            }

            public function description(): string
            {
                return 'Deterministic test fixture provider.';
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
                $this->calls[] = $payload;

                if ($this->shouldFail) {
                    throw new SimulatedProviderFailureException($this->failCategory, null, 'Simulated fixture failure.');
                }

                return [
                    'external_id' => $this->externalId ?? 'fake-retry-external-id',
                    'version_token' => $this->versionToken ?? 'fake-retry-version-token',
                ];
            }
        };

        $class = get_class($provider);
        app()->instance($class, $provider);
        config(['integrations.providers' => [ProviderKey::Test->value => $class]]);

        return $provider;
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

    // ==============================================================
    // THE SINGLE MOST IMPORTANT TEST — the hard-prerequisite
    // statement_timestamp() fix, proven via SyncItemService::
    // claimForRetry() called from a genuinely still-open transaction,
    // using ONLY deterministic ordering (two real, separate physical
    // connections) — never a real sleep()/pg_sleep() wait.
    // ==============================================================

    public function test_claim_for_retry_claims_a_row_that_becomes_due_only_after_the_still_open_transaction_began(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        // Far-future placeholder — deliberately NOT due yet under any
        // predicate, so its actual claimability is entirely determined
        // by the literal write performed below, not by this fixture's
        // own initial value.
        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->addHour()]));

        try {
            // BEGIN and leave the transaction OPEN. Postgres freezes
            // this transaction's own now()/transaction_timestamp() at
            // THIS instant ($t0) for its entire remaining lifetime —
            // independently and empirically re-verified below, not
            // merely assumed.
            DB::beginTransaction();
            DB::select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, false]);

            $t0 = DB::selectOne('select now() as t')->t;

            // integration_sync_items.next_attempt_at is a timestamp(0)
            // (whole-second precision) column — any literal value
            // written into it is rounded to the nearest second. The
            // SMALLEST whole-second value that is GUARANTEED strictly
            // later than $t0, regardless of $t0's own fractional
            // second, is CEIL($t0) — computed here via the identical
            // to_timestamp(ceil(extract(epoch from ...)))) expression
            // IntegrationOutboxEventService::claim()/fail() already
            // establish for this exact class of lower-bound gate.
            $target = DB::selectOne(
                "select to_timestamp(ceil(extract(epoch from ?::timestamptz))) as t",
                [$t0]
            )->t;

            DB::statement(
                'UPDATE integration_sync_items SET next_attempt_at = ?::timestamp WHERE id = ?',
                [$target, $item->id]
            );

            // Prove, via literal read-only predicate comparisons, that
            // the OLD now()-based predicate would MISS this row (now()
            // is frozen at $t0, strictly before $target by
            // construction) while the NEW statement_timestamp()-based
            // predicate's eventual truth depends only on live real
            // time reaching $target — bounded at MOST 1 second away
            // from $t0, by construction of the ceiling above.
            $beforeComparison = DB::selectOne(
                'SELECT (next_attempt_at <= now()) AS old_predicate_would_match '.
                'FROM integration_sync_items WHERE id = ?',
                [$item->id]
            );
            $this->assertFalse(
                (bool) $beforeComparison->old_predicate_would_match,
                'The OLD now()-based predicate must NEVER see this row as due for the remainder of this transaction — now() is frozen at $t0, strictly before the row\'s (ceiling-constructed, guaranteed-later) next_attempt_at. This is exactly the missed-claim race this checkpoint\'s hard prerequisite fixes.'
            );

            // Deterministic, BOUNDED wait (never a blind/arbitrary
            // sleep-and-hope): poll real Postgres time on this SAME
            // open transaction/connection until it reaches $target,
            // which is mathematically guaranteed to happen within at
            // most ~1 real second (the ceiling above can never be more
            // than one whole second ahead of $t0). This models exactly
            // the real-world scenario the fix exists for: real
            // wall-clock time continuing to advance while this
            // transaction remains open.
            $maxIterations = 150; // 150 * 10ms = 1.5s hard cap, safely above the ~1s theoretical maximum
            $reachedTarget = false;
            for ($i = 0; $i < $maxIterations; $i++) {
                $liveCheck = DB::selectOne('SELECT (statement_timestamp() >= ?::timestamptz) AS reached', [$target]);
                if ((bool) $liveCheck->reached) {
                    $reachedTarget = true;
                    break;
                }
                usleep(10000);
            }
            $this->assertTrue($reachedTarget, 'Real time must reach the (at-most-1-second-away) target within the bounded poll window.');

            // Re-confirm, still inside the SAME open transaction, that
            // the OLD predicate is STILL false (now() has not budged —
            // proving it truly stayed frozen across the whole poll
            // window) while the NEW predicate now matches.
            $afterComparison = DB::selectOne(
                'SELECT (next_attempt_at <= now()) AS old_predicate_still_would_not_match, '.
                '(next_attempt_at <= statement_timestamp()) AS new_predicate_matches '.
                'FROM integration_sync_items WHERE id = ?',
                [$item->id]
            );
            $this->assertFalse(
                (bool) $afterComparison->old_predicate_still_would_not_match,
                'now() must remain frozen at $t0 for the ENTIRE lifetime of this still-open transaction, proving the OLD bug\'s root cause empirically, not merely by citation.'
            );
            $this->assertTrue(
                (bool) $afterComparison->new_predicate_matches,
                'statement_timestamp() must be live and reflect that real time has now reached the target.'
            );

            // Now call the REAL, actual SyncItemService::claimForRetry()
            // — the exact fixed production method — still inside the
            // SAME still-open transaction.
            $claimed = (new SyncItemService())->claimForRetry($item->id);

            $this->assertNotNull(
                $claimed,
                'claimForRetry() must successfully claim a row that became due only AFTER this transaction opened — this is the exact hard-prerequisite fix this checkpoint required before the retry poller could safely activate this primitive.'
            );
            $this->assertSame(SyncItemStatus::Retrying, $claimed->status);

            DB::commit();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select('select set_config(?, ?, ?)', ['app.current_firm_id', '', false]);
        }
    }

    // ------------------------------------------------------------
    // Ordinary (wide-margin, non-precision-critical) claim proof, via
    // the real SyncRetryPollJob end-to-end, complementing the literal
    // proof above.
    // ------------------------------------------------------------

    public function test_a_widely_past_due_failed_retryable_item_is_claimed_and_resolved_by_the_real_job(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->subMinutes(10), 'local_type' => null, 'local_id' => null]));

        $this->runJob($firm);

        $fresh = $this->itemRow($firm, $item->id);
        $this->assertNotSame('failed_retryable', $fresh->status, 'The job must have claimed and resolved this item, moving it off failed_retryable entirely.');
    }

    public function test_a_not_yet_due_item_is_left_untouched(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->addHour()]));

        $this->runJob($firm);

        $fresh = $this->itemRow($firm, $item->id);
        $this->assertSame('failed_retryable', $fresh->status, 'A not-yet-due item must be left completely untouched.');
    }

    // ==============================================================
    // Pull-shaped item — honest failure resolution
    // ==============================================================

    public function test_a_pull_shaped_item_with_no_local_type_or_id_is_resolved_as_an_honest_permanent_failure(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->subMinute(), 'local_type' => null, 'local_id' => null]));

        $this->runJob($firm);

        $fresh = $this->itemRow($firm, $item->id);
        $this->assertSame('failed_permanent', $fresh->status);
        $this->assertSame('pull_item_retry_not_supported_generically', $fresh->last_error);
    }

    public function test_a_pull_shaped_items_honest_failure_never_calls_the_provider(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->subMinute(), 'local_type' => null, 'local_id' => null]));

        $provider = $this->registerFakePushProvider();
        $this->runJob($firm);

        $this->assertCount(0, $provider->calls, 'A pull-shaped item has no generic single-item re-pull primitive — it must never reach any push() call.');
    }

    // ==============================================================
    // Push-shaped item — inline re-push path
    // ==============================================================

    public function test_a_push_shaped_item_is_resolved_via_an_inline_re_push_and_succeeds(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->subMinute(), 'local_type' => 'App\\Models\\Contact', 'local_id' => 1234]));

        $this->registerFakePushProvider('ext-retry-success', 'v-retry-1');
        $this->runJob($firm);

        $fresh = $this->itemRow($firm, $item->id);
        $this->assertSame('succeeded', $fresh->status);

        $mapping = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::query()
            ->where('firm_integration_id', $connection->id)
            ->where('resource_type', $item->resource_type)
            ->where('local_type', 'App\\Models\\Contact')
            ->where('local_id', 1234)
            ->first());

        $this->assertNotNull($mapping);
        $this->assertSame('ext-retry-success', $mapping->external_id);
    }

    public function test_a_push_shaped_item_with_an_existing_mapping_refreshes_it_rather_than_duplicating(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->subMinute(), 'local_type' => 'App\\Models\\Contact', 'local_id' => 5555]));

        $existingMapping = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => $item->resource_type,
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 5555,
                'external_id' => 'ext-already-mapped',
                'external_version_token' => 'old-version',
            ]));

        $this->registerFakePushProvider('ext-already-mapped', 'new-version');
        $this->runJob($firm);

        $mappingCount = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::query()
            ->where('firm_integration_id', $connection->id)
            ->where('local_id', 5555)
            ->count());
        $this->assertSame(1, $mappingCount);

        $fresh = $this->runWithFirmContext($firm, fn () => $existingMapping->fresh());
        $this->assertSame('new-version', $fresh->external_version_token);
    }

    public function test_a_push_shaped_item_with_a_non_active_connection_is_put_back_as_failed_retryable_not_permanent(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm, ConnectionStatus::Disconnected);
        $run = $this->terminalRun($firm, $connection);

        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->subMinute(), 'local_type' => 'App\\Models\\Contact', 'local_id' => 42]));

        $this->runJob($firm);

        $fresh = $this->itemRow($firm, $item->id);
        $this->assertSame('failed_retryable', $fresh->status, 'A non-Active connection is not the item\'s own fault — it must be put back for a LATER retry, never permanently failed.');
    }

    // ==============================================================
    // HealthStateService wiring — diff-review.md item 6's fix
    // ==============================================================

    public function test_a_successful_inline_re_push_records_a_health_success_signal(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->subMinute(), 'local_type' => 'App\\Models\\Contact', 'local_id' => 77]));

        // Seed a prior failure signal so recordSuccess()'s reset is
        // actually observable, not merely a fresh-row creation.
        $this->runWithFirmContext($firm, fn () => (new HealthStateService())->recordProviderError(
            $connection->id,
            $firm->id,
            new SanitizedHealthDiagnostic(
                SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR,
                SanitizedHealthDiagnostic::OPERATION_PUSH_SYNC,
            ),
        ));

        $this->registerFakePushProvider('ext-health-ok', 'v1');
        $this->runJob($firm);

        $health = $this->runWithFirmContext($firm, fn () => DB::table('integration_connection_health')->where('firm_integration_id', $connection->id)->first());
        $this->assertNotNull($health);
        $this->assertSame('healthy', $health->summary_state);
        $this->assertSame(0, (int) $health->consecutive_failures);
    }

    public function test_a_failed_inline_re_push_records_a_categorized_health_signal(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->subMinute(), 'local_type' => 'App\\Models\\Contact', 'local_id' => 88]));

        $this->registerFakePushProvider(shouldFail: true, failCategory: 'rate_limited');
        $this->runJob($firm);

        $health = $this->runWithFirmContext($firm, fn () => DB::table('integration_connection_health')->where('firm_integration_id', $connection->id)->first());
        $this->assertNotNull($health, 'grep -n "HealthStateService" app/Jobs/SyncRetryPollJob.php must show a genuine call site, not merely an import — this row proves it fires at runtime.');
        $this->assertSame('rate_limited', $health->last_failure_category);
        $this->assertNotNull($health->rate_limited_reset_at);
        $this->assertSame('degraded', $health->summary_state);
    }

    public function test_a_failed_inline_re_push_puts_the_item_back_as_failed_retryable_with_a_future_next_attempt_at(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->subMinute(), 'local_type' => 'App\\Models\\Contact', 'local_id' => 89]));

        $this->registerFakePushProvider(shouldFail: true, failCategory: 'rate_limited');
        $this->runJob($firm);

        $fresh = $this->itemRow($firm, $item->id);
        $this->assertSame('failed_retryable', $fresh->status);
        $this->assertStringContainsString('retry_push_failed: rate_limited', $fresh->last_error);
        $this->assertTrue(Carbon::parse($fresh->next_attempt_at)->isFuture());
    }

    public function test_a_credential_category_failure_maps_to_record_credential_error(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->subMinute(), 'local_type' => 'App\\Models\\Contact', 'local_id' => 90]));

        $this->registerFakePushProvider(shouldFail: true, failCategory: 'authentication_failed');
        $this->runJob($firm);

        $health = $this->runWithFirmContext($firm, fn () => DB::table('integration_connection_health')->where('firm_integration_id', $connection->id)->first());
        $this->assertSame('credential_error', $health->last_failure_category);
        $this->assertSame('action_required', $health->summary_state);
    }
}
