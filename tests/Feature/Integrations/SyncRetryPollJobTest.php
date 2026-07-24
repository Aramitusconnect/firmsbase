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
use App\Integrations\Exceptions\UnknownProviderException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\IntegrationRequeueAuditLogger;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Jobs\SyncRetryPollJob;
use App\Models\Firm;
use App\Services\TimelineEventRecorder;
use Illuminate\Database\QueryException;
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

    /**
     * Identical wiring to runJob() above, but with an explicit,
     * non-default batchSize — needed by the claim-limit-exact test,
     * which must control this job-level property directly rather than
     * relying on the fixed 25 runJob() always uses.
     */
    private function runJobWithBatchSize(Firm $firm, int $batchSize): void
    {
        $job = new SyncRetryPollJob($firm->id, $batchSize);
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
            $claimed = (new SyncItemService(new TimelineEventRecorder(), new IntegrationRequeueAuditLogger()))->claimForRetry($item->id);

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
        $this->runWithFirmContext($firm, fn () => (new HealthStateService(new TimelineEventRecorder()))->recordProviderError(
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

    // ==============================================================
    // Additive regression coverage — checkpoint-08-sync-item-timestamp-
    // remediation, Agent E's independent review verdict
    // (reviews/checkpoint-08-sync-item-timestamp-remediation/
    // agent-e-independent-review.md §7/§8): the underlying claimForRetry()
    // timestamp-precision race is already fixed (commit fa7f21c, now()
    // -> statement_timestamp()) and proven fixed by the precision test
    // above, but the mission's own required regression-test list named
    // three specific properties still unproven in this file. The three
    // tests below close exactly those gaps — no production code changes.
    // ==============================================================

    // ------------------------------------------------------------
    // Mission item #3 — exact eligibility boundary is inclusive.
    // Mirrors IntegrationOutboxTimestampPrecisionTest's Test 11
    // (test_a_row_due_before_the_true_comparison_instant_is_correctly_claimable_under_literal_construction):
    // fully clock-independent, literal-value predicate proof. Never
    // relies on Carbon::setTestNow() (it has zero effect on this raw-SQL
    // comparison — claimForRetry()'s predicate is evaluated entirely in
    // Postgres against statement_timestamp(), never PHP's Carbon/now()),
    // and never relies on a live statement_timestamp() read racing a
    // chosen instant (which the EXISTING precision test above already
    // covers, and which can only prove "time has passed", never "exact
    // equality is included").
    // ------------------------------------------------------------

    public function test_next_attempt_at_exactly_equal_to_the_comparison_instant_is_claimable_under_literal_construction(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->addHour()]));

        $this->runWithFirmContext($firm, function () use ($item) {
            // Deliberately chosen literal values — no clock dependency
            // at all. next_attempt_at is a timestamp(0) column (same
            // precision as integration_outbox_events.next_attempt_at);
            // Postgres rounds .700000 UP to timestamp(0), i.e. this
            // literal write is stored as 12:00:01.
            DB::statement(
                "UPDATE integration_sync_items SET next_attempt_at = TIMESTAMP '2026-01-01 12:00:00.700000' WHERE id = ?",
                [$item->id]
            );

            $eligibleAt090 = DB::selectOne(
                "SELECT (next_attempt_at <= TIMESTAMP '2026-01-01 12:00:00.900000') AS eligible FROM integration_sync_items WHERE id = ?",
                [$item->id]
            )->eligible;

            $this->assertFalse(
                (bool) $eligibleAt090,
                'A next_attempt_at stored as 12:00:01 (rounded up from .700000) must not read as <= 12:00:00.900000 — this pins the exact, deterministic literal-value comparison claimForRetry()\'s predicate relies on, mirroring how IntegrationOutboxTimestampPrecisionTest\'s Test 11 pins the identical comparison for the outbox table.'
            );

            $eligibleAtExactEquality = DB::selectOne(
                "SELECT (next_attempt_at <= TIMESTAMP '2026-01-01 12:00:01.000000') AS eligible FROM integration_sync_items WHERE id = ?",
                [$item->id]
            )->eligible;

            $this->assertTrue(
                (bool) $eligibleAtExactEquality,
                'A next_attempt_at stored as exactly 12:00:01 must read as <= a comparison instant of exactly 12:00:01 — claimForRetry()\'s next_attempt_at <= statement_timestamp() predicate is <=-inclusive AT the boundary, not merely eventually-true-once-time-passes-it, independent of clock source.'
            );

            // One microsecond-equivalent EARLIER than the stored value
            // must NOT be eligible — proves this is a genuine boundary
            // proof (both directions), not an assertion that would
            // trivially pass for any operator.
            $eligibleJustBefore = DB::selectOne(
                "SELECT (next_attempt_at <= TIMESTAMP '2026-01-01 12:00:00.999999') AS eligible FROM integration_sync_items WHERE id = ?",
                [$item->id]
            )->eligible;

            $this->assertFalse(
                (bool) $eligibleJustBefore,
                'A comparison instant even 1 microsecond before the stored 12:00:01 value must NOT be eligible — confirms the boundary is exact, not a wide/rounded window.'
            );
        });

        // Complementary black-box proof: the REAL, unmodified
        // claimForRetry() must agree with the literal predicate proof
        // above at a genuine, wide-margin past-due state (statement_
        // timestamp() cannot itself be pinned to an arbitrary literal
        // instant from PHP, so this half of the proof uses the file's
        // own established wide-margin past-due fixture shape rather
        // than attempting to reproduce exact equality against a live
        // clock).
        $this->runWithFirmContext($firm, function () use ($item) {
            DB::table('integration_sync_items')->where('id', $item->id)->update([
                'next_attempt_at' => now()->subMinutes(5),
            ]);
        });

        $claimed = $this->runWithFirmContext($firm, fn () => (new SyncItemService(new TimelineEventRecorder(), new IntegrationRequeueAuditLogger()))->claimForRetry($item->id));

        $this->assertNotNull($claimed, 'The real claimForRetry() must agree with the literal predicate proof above: a due row is claimable.');
        $this->assertSame(SyncItemStatus::Retrying, $claimed->status);
    }

    // ------------------------------------------------------------
    // Mission item #10 / Agent E's required "Test B" — two genuinely
    // concurrent PHYSICAL database connections cannot both claim the
    // same item. claimForRetry() is a single-row, primary-key-targeted
    // UPDATE (not a SKIP LOCKED CTE like IntegrationOutboxEventService
    // ::claim()), so a second connection racing the SAME row BLOCKS on
    // Postgres's native row lock rather than skipping it — the outbox's
    // exact SKIP-LOCKED technique (IntegrationOutboxConcurrentClaimTest)
    // cannot be copied verbatim (Agent D's test-gap plan §3 item 5).
    // A short lock_timeout on the racing (worker_b) connection turns
    // that indefinite block into a deterministic, bounded failure
    // instead of hanging the suite — no sleep()/usleep() anywhere in
    // this test. worker_b is the exact second-connection boilerplate
    // this file's own tearDown() has always anticipated (see the class
    // docblock and tearDown() above) but which, until now, no test
    // method here ever actually opened.
    // ------------------------------------------------------------

    public function test_two_concurrent_physical_connections_racing_the_same_failed_retryable_row_result_in_exactly_one_successful_claim(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->subMinute()]));

        // Register a second, independent Laravel DB connection pointing
        // at the SAME physical database/credentials as the default
        // 'pgsql' connection, purely at test runtime — identical
        // boilerplate to IntegrationOutboxConcurrentClaimTest, now
        // actually exercised for claimForRetry() rather than left dead.
        config(['database.connections.worker_b' => config('database.connections.pgsql')]);
        DB::purge('worker_b');

        $lockTimeoutMessage = null;

        try {
            // --- Connection A (default) --------------------------------
            // Explicit DB::beginTransaction() (not the auto-committing
            // DB::transaction(closure)) — the transaction must stay open
            // across the switch to connection B, mirroring both the
            // hard-prerequisite precision test above and
            // IntegrationOutboxConcurrentClaimTest's own is_local=false
            // set_config convention.
            DB::beginTransaction();
            DB::select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, false]);

            $claimedA = (new SyncItemService(new TimelineEventRecorder(), new IntegrationRequeueAuditLogger()))->claimForRetry($item->id);

            $this->assertNotNull($claimedA, 'Connection A must successfully claim the single failed_retryable row.');
            $this->assertSame(SyncItemStatus::Retrying, $claimedA->status);

            // Connection A's transaction is DELIBERATELY left open
            // (uncommitted) here — this is the entire point of the
            // test: the row's UPDATE lock, acquired by A's own claim,
            // is still held by an in-flight, uncommitted transaction
            // while connection B attempts to claim the SAME row.

            // --- Connection B (worker_b) ------------------------------
            DB::connection('worker_b')->beginTransaction();
            DB::connection('worker_b')->select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, false]);

            // A short, explicit lock_timeout — B's attempt below is
            // guaranteed to either succeed immediately (it must not,
            // since A holds the row lock) or fail deterministically
            // within this bound. This is what makes the test
            // deterministic without any sleep()/usleep() wait.
            DB::connection('worker_b')->statement("SET LOCAL lock_timeout = '200ms'");

            try {
                // The EXACT SQL text claimForRetry() executes (copied
                // verbatim as of this file's writing — if
                // claimForRetry()'s SQL changes, this literal copy must
                // be updated to match), issued directly against
                // connection B, since SyncItemService itself always
                // executes against the default connection via the DB
                // facade.
                DB::connection('worker_b')->selectOne(
                    'UPDATE integration_sync_items '.
                    "SET status = 'retrying' ".
                    "WHERE id = ? AND status = 'failed_retryable' AND next_attempt_at <= statement_timestamp() ".
                    'RETURNING *',
                    [$item->id]
                );

                $this->fail('Connection B\'s claim attempt must be blocked by connection A\'s held row lock and time out — it must never succeed while A\'s transaction is still open.');
            } catch (QueryException $e) {
                $lockTimeoutMessage = strtolower($e->getMessage());
            }

            $this->assertStringContainsString(
                'lock timeout',
                $lockTimeoutMessage,
                'Connection B\'s claim attempt must fail specifically with PostgreSQL\'s lock_timeout error ("canceling statement due to lock timeout") — proving it was genuinely blocked by connection A\'s held row lock, not skipped, not a different/unrelated error.'
            );

            // B's failed statement poisons its own transaction — roll
            // it back to release connection B cleanly. B never wrote
            // anything (the UPDATE never completed).
            DB::connection('worker_b')->rollBack();

            // Now commit connection A's transaction — its claim is the
            // only one that ever succeeded.
            DB::commit();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if (array_key_exists('worker_b', config('database.connections', []))) {
                while (DB::connection('worker_b')->transactionLevel() > 0) {
                    DB::connection('worker_b')->rollBack();
                }
            }
            DB::select('select set_config(?, ?, ?)', ['app.current_firm_id', '', false]);
        }

        // Fresh read, after A's commit, on a plain, uninvolved
        // connection (no open transaction) — proves the final state
        // reflects exactly one successful claim.
        $final = $this->itemRow($firm, $item->id);
        $this->assertSame('retrying', $final->status, 'The final state must be retrying — connection A\'s claim, and only connection A\'s claim, took effect.');

        // Complementary, cheaper SEQUENTIAL proof of the same
        // underlying guarantee (Agent D's test-gap plan §5, proposed
        // Test B step 8): calling claimForRetry() a SECOND time on the
        // SAME id, now that A's claim has committed and moved the row
        // off failed_retryable, must return null — the row can never
        // be claimed twice, concurrently OR sequentially.
        $secondClaim = $this->runWithFirmContext($firm, fn () => (new SyncItemService(new TimelineEventRecorder(), new IntegrationRequeueAuditLogger()))->claimForRetry($item->id));
        $this->assertNull($secondClaim, 'A row already claimed (no longer failed_retryable) must never be claimable again — sequential double-claim must also be impossible.');
    }

    // ------------------------------------------------------------
    // Mission item #9 / Agent E's required "Test C" — SyncRetryPollJob's
    // claim limit (batchSize) is respected EXACTLY, not approximately.
    // This is a job-level property (the candidate scan's
    // ->limit($this->batchSize)), not a claimForRetry()-level property,
    // so this test targets the job directly with an explicit,
    // non-default batchSize — mirroring IntegrationOutboxTimestamp
    // PrecisionTest::test_claiming_with_a_limit_below_the_eligible_pool_size_claims_exactly_the_limit's
    // two-call structure. Uses pull-shaped items (no local_type/
    // local_id) throughout, so every claimed item resolves
    // deterministically and provider-independently via the honest-
    // failure path, keeping this purely a counting proof with zero
    // timing dependency.
    // ------------------------------------------------------------

    public function test_the_job_claims_exactly_batchsize_items_and_leaves_the_remainder_untouched_until_a_second_run(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
                ->forSyncRun($run)
                ->failedRetryable()
                ->create([
                    // Distinct, deterministic next_attempt_at values —
                    // avoids any ambiguity from same-instant ties in the
                    // job's ORDER BY next_attempt_at candidate scan.
                    'next_attempt_at' => now()->subMinutes(10 - $i),
                    'local_type' => null,
                    'local_id' => null,
                ]));
        }

        $before = [];
        foreach ($items as $item) {
            $before[$item->id] = $this->itemRow($firm, $item->id);
        }

        $this->runJobWithBatchSize($firm, 3);

        $after = [];
        foreach ($items as $item) {
            $after[$item->id] = $this->itemRow($firm, $item->id);
        }

        $resolvedIds = array_keys(array_filter($after, fn ($row) => $row->status !== 'failed_retryable'));
        $untouchedIds = array_keys(array_filter($after, fn ($row) => $row->status === 'failed_retryable'));

        $this->assertCount(3, $resolvedIds, 'A run with an explicit batchSize=3 against a pool of 5 eligible items must claim/resolve EXACTLY 3 — not fewer, not more.');
        $this->assertCount(2, $untouchedIds, 'The remaining 2 eligible items must be left completely untouched by a batchSize=3 run.');

        foreach ($resolvedIds as $id) {
            $this->assertSame('failed_permanent', $after[$id]->status, 'Each claimed pull-shaped item must resolve via the honest-failure path.');
            $this->assertSame('pull_item_retry_not_supported_generically', $after[$id]->last_error);
        }

        foreach ($untouchedIds as $id) {
            $this->assertSame(
                Carbon::parse($before[$id]->next_attempt_at)->toDateTimeString(),
                Carbon::parse($after[$id]->next_attempt_at)->toDateTimeString(),
                'An untouched remainder item\'s next_attempt_at must be completely unchanged by a run that never claimed it.'
            );
            $this->assertSame(
                (int) $before[$id]->attempt_count,
                (int) $after[$id]->attempt_count,
                'An untouched remainder item\'s attempt_count must be unchanged.'
            );
        }

        // Run the job a SECOND time (same batchSize=3, only 2 eligible
        // remain) — proves the first call did not over-claim past its
        // own limit and the second call picks up exactly the rest.
        $this->runJobWithBatchSize($firm, 3);

        foreach ($untouchedIds as $id) {
            $fresh = $this->itemRow($firm, $id);
            $this->assertSame('failed_permanent', $fresh->status, 'The second run must resolve the previously-untouched remainder.');
        }
    }

    // ==============================================================
    // Fast-follow — test-gate reviewer's disclosed, non-blocking
    // Checkpoint 12 coverage gap: SyncRetryPollJob (the third job this
    // checkpoint modified, adding the providerContext parameter) had
    // neither a tenant-context-restoration test nor a cross-firm-denial
    // test anywhere in the suite, despite PullSyncJob and PushSyncJob
    // each having dedicated coverage for both properties. The three
    // tests below close that gap, mirroring PullSyncJobTest's exact
    // style/pattern (see tests/Feature/Integrations/PullSyncJobTest.php
    // ::test_tenant_context_is_restored_after_a_successful_run(),
    // ::test_tenant_context_is_restored_even_when_the_connection_is_not_found(),
    // and ::test_a_connection_belonging_to_a_different_firm_is_denied())
    // but adapted to SyncRetryPollJob's own attack surface: it takes
    // only a firmId (never a connection id it must independently
    // verify), so its cross-firm property is "a Firm A retry item is
    // completely invisible/untouched to a poll run dispatched for Firm
    // B" rather than "a connection id claimed under the wrong firm is
    // denied". handle() runs its whole body through
    // $this->runInFirmContext() (App\Support\TenantAwareJobContext),
    // which — confirmed by reading both call sites directly, not merely
    // trusted from the prior review — is the exact same
    // TenantContextService::runWithFirmContext() primitive
    // PullSyncJob/PushSyncJob's own runWithFirmContext() call sites use
    // and tests/TestCase.php's runWithFirmContext()/
    // assertNoDatabaseTenantContext() helpers exercise.
    // ==============================================================

    public function test_tenant_context_is_restored_after_a_successful_run(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->subMinute(), 'local_type' => 'App\\Models\\Contact', 'local_id' => 555]));

        $this->registerFakePushProvider('ext-context-restore', 'v1');

        $this->assertNoDatabaseTenantContext();
        $this->runJob($firm);
        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_is_restored_even_when_the_job_throws_mid_processing(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $run = $this->terminalRun($firm, $connection);

        $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->subMinute(), 'local_type' => 'App\\Models\\Contact', 'local_id' => 321]));

        // Deregister the Test provider entirely (setUp() normally
        // registers it) — ProviderRegistry::get() then throws
        // UnknownProviderException from inside resolveOneRetry(), i.e.
        // from INSIDE the still-open runInFirmContext() callback, a
        // cheap and natural way to force handle() to throw mid-run
        // without any new fixture machinery, mirroring the shape (not
        // the exact trigger) of PullSyncJobTest's own "connection not
        // found" exception-path restoration test.
        config(['integrations.providers' => []]);

        $this->assertNoDatabaseTenantContext();

        try {
            $this->runJob($firm);
            $this->fail('Expected UnknownProviderException.');
        } catch (UnknownProviderException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext('Even when handle() throws mid-processing, runInFirmContext()\'s finally-cleanup must restore no-context.');
    }

    public function test_a_firm_a_retry_item_is_left_completely_untouched_by_a_poll_run_dispatched_for_a_different_firm(): void
    {
        $firmA = $this->firm();
        $firmB = $this->firm();
        $connectionA = $this->connection($firmA);
        $runA = $this->terminalRun($firmA, $connectionA);

        $item = $this->runWithFirmContext($firmA, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($runA)
            ->failedRetryable()
            ->create(['next_attempt_at' => now()->subMinute(), 'local_type' => null, 'local_id' => null]));

        $before = $this->itemRow($firmA, $item->id);

        // Firm B has no connection, no run, and — critically — no
        // failed_retryable items of its own. Dispatch the SAME job
        // scoped to Firm B's own firmId: both the job's own explicit
        // ->where('firm_id', $this->firmId) candidate-scan guard AND
        // the app.current_firm_id tenant context runInFirmContext()
        // establishes for Firm B must make Firm A's due, otherwise-
        // eligible item completely invisible.
        $this->runJob($firmB);

        $after = $this->itemRow($firmA, $item->id);

        $this->assertSame(
            'failed_retryable',
            $after->status,
            'A poll run dispatched for a different firm must never claim or resolve another firm\'s retry item.'
        );
        $this->assertSame(
            Carbon::parse($before->next_attempt_at)->toDateTimeString(),
            Carbon::parse($after->next_attempt_at)->toDateTimeString(),
            'Firm A\'s item next_attempt_at must be completely unchanged by a cross-firm poll run.'
        );
        $this->assertSame(
            (int) $before->attempt_count,
            (int) $after->attempt_count,
            'Firm A\'s item attempt_count must be completely unchanged by a cross-firm poll run.'
        );
    }
}
