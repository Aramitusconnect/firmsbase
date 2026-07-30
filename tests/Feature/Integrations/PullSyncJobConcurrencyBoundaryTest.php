<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CursorStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Exceptions\SimulatedProviderFailureException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncCursor;
use App\Integrations\Services\IntegrationConflictService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncCursorService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Services\SyncRunService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Jobs\PullSyncJob;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\TenantContextService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PurgesDurableProviderOperationAttempts;
use Tests\TestCase;

/**
 * PullSyncJobConcurrencyBoundaryTest — Checkpoint 8.2 §A6. Proves the
 * transaction/lock boundary of `PullSyncJob`, which previously wrapped
 * its entire run — every provider HTTP call included — in one
 * transaction holding `FOR UPDATE` on its `firm_integrations` row.
 *
 * Four properties, in order of importance:
 *
 *   1. NO ROW LOCK is held on the connection row while a provider call is
 *      in flight. Proven with a genuinely separate database session
 *      issuing `SELECT ... FOR UPDATE NOWAIT` from inside the provider's
 *      own `pull()`. That probe is itself validated by a positive control
 *      in the same suite, so a false pass is not possible: the control
 *      takes the lock deliberately and asserts the probe DOES fail.
 *   2. NO TRANSACTION is opened around the provider call.
 *   3. Each page commits on its own, so an interruption at page N keeps
 *      pages 1..N-1 and leaves the cursor at the last applied page.
 *   4. A cursor claim abandoned by a killed worker can be taken over
 *      after its lease lapses, and NOT before.
 *
 * The connection fixture here is committed on the independent
 * `pgsql_audit` connection, because a second session cannot lock — or
 * even see — a row that only exists inside RefreshDatabase's own
 * uncommitted transaction. It is deleted again in
 * `beforeApplicationDestroyed()`, mirroring
 * ProviderBillableCallPipelineDurableGateTest's identical discipline.
 */
class PullSyncJobConcurrencyBoundaryTest extends TestCase
{
    use PurgesDurableProviderOperationAttempts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDurableProviderOperationAttempts();
    }

    private const PROBE_CONNECTION = 'pgsql_audit';

    /**
     * A Firm + FirmIntegration that really exist for every database
     * session, not just this test's transaction.
     *
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function committedConnection(): array
    {
        $firm = Firm::factory()->connection(self::PROBE_CONNECTION)->create();

        // Every table below is FORCE-RLS protected, and this is a
        // genuinely separate database session, so it needs its own tenant
        // context — session-scoped (`is_local = false`) because the probe
        // reads happen outside any transaction on this connection. Cleared
        // again in the cleanup hook, so nothing leaks to a later test.
        $this->setProbeFirmContext((int) $firm->id);

        TenantEncryptionKey::factory()->connection(self::PROBE_CONNECTION)->forFirm($firm)->create();

        $connection = FirmIntegration::factory()
            ->connection(self::PROBE_CONNECTION)
            ->forFirm($firm)
            ->create([
                'status' => ConnectionStatus::Active->value,
                'external_account_id' => null,
                // The factory's default would create a FirmUser on the
                // DEFAULT connection, inside RefreshDatabase's uncommitted
                // transaction — invisible to this session, so its FK would
                // fail. The column is nullable and nothing here needs it.
                'connected_by_firm_user_id' => null,
            ]);

        $this->beforeApplicationDestroyed(function () use ($firm, $connection) {
            $probe = DB::connection(self::PROBE_CONNECTION);
            $this->setProbeFirmContext((int) $firm->id);

            $runIds = $probe->table('integration_sync_runs')->where('firm_integration_id', $connection->id)->pluck('id');

            if ($runIds->isNotEmpty()) {
                $probe->table('integration_sync_items')->whereIn('sync_run_id', $runIds)->delete();
            }

            $probe->table('integration_sync_runs')->where('firm_integration_id', $connection->id)->delete();
            $probe->table('integration_sync_cursors')->where('firm_integration_id', $connection->id)->delete();
            $probe->table('integration_external_mappings')->where('firm_integration_id', $connection->id)->delete();
            $probe->table('timeline_events')->where('firm_id', $firm->id)->delete();
            $probe->table('firm_integrations')->where('id', $connection->id)->delete();
            $probe->table('tenant_encryption_keys')->where('firm_id', $firm->id)->delete();
            $probe->table('firms')->where('id', $firm->id)->delete();

            $this->clearProbeFirmContext();
        });

        return [$firm, $connection];
    }

    private function setProbeFirmContext(int $firmId): void
    {
        DB::connection(self::PROBE_CONNECTION)
            ->select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firmId, false]);
    }

    private function clearProbeFirmContext(): void
    {
        DB::connection(self::PROBE_CONNECTION)
            ->select('select set_config(?, ?, ?)', ['app.current_firm_id', '', false]);
    }

    /**
     * Registers a deterministic pull provider whose `pull()` runs
     * `$duringCall` before returning the next page — the hook every test
     * below uses to observe the job's state from INSIDE the network
     * window.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $itemsPerPage
     */
    private function registerObservingProvider(array $itemsPerPage, ?Closure $duringCall = null, ?int $failOnPageIndex = null): void
    {
        $provider = new class($itemsPerPage, $duringCall, $failOnPageIndex) implements IntegrationProviderContract, SupportsPullSyncContract
        {
            public function __construct(
                private readonly array $itemsPerPage,
                private readonly ?Closure $duringCall,
                private readonly ?int $failOnPageIndex,
            ) {}

            public function key(): ProviderKey
            {
                return ProviderKey::Test;
            }

            public function displayName(): string
            {
                return 'Observing Pull Provider';
            }

            public function description(): string
            {
                return 'Deterministic fixture that lets a test observe job state mid-call.';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function supportedAuthMethods(): array
            {
                return [AuthMethod::None];
            }

            public function pullableResourceTypes(): array
            {
                return ['contact'];
            }

            public function pull(array $context, string $resourceType, ?string $cursor): array
            {
                $pageIndex = $cursor === null ? 0 : (int) $cursor;

                if ($this->duringCall !== null) {
                    ($this->duringCall)($pageIndex);
                }

                if ($this->failOnPageIndex === $pageIndex) {
                    throw new SimulatedProviderFailureException('network_error', null, 'Simulated mid-sync failure.');
                }

                $items = $this->itemsPerPage[$pageIndex] ?? [];
                $nextCursor = array_key_exists($pageIndex + 1, $this->itemsPerPage) ? (string) ($pageIndex + 1) : null;

                return ['items' => $items, 'next_cursor' => $nextCursor];
            }
        };

        $class = $provider::class;
        app()->instance($class, $provider);
        config(['integrations.providers' => [ProviderKey::Test->value => $class]]);
    }

    private function dispatchPull(FirmIntegration $connection, int $firmId): void
    {
        (new PullSyncJob($connection->id, $firmId, 'contact'))->handle(
            app(SyncRunService::class),
            app(SyncItemService::class),
            app(SyncCursorService::class),
            app(IntegrationExternalMappingService::class),
            app(IntegrationConflictService::class),
            app(ProviderRegistry::class),
            app(OutboundProviderHttpClient::class),
        );
    }

    /**
     * Asks the ONE question that matters, from a genuinely separate
     * database session: could a durable cross-session write that
     * references this connection row proceed RIGHT NOW?
     *
     * The lock probed for is `FOR KEY SHARE`, not `FOR UPDATE`, because
     * that is exactly what PostgreSQL takes on a referenced row when
     * another session inserts a row whose foreign key points at it — the
     * precise lock Checkpoint 8.1's durable insert needed and could not
     * get while this job held `FOR UPDATE`. `FOR KEY SHARE` conflicts with
     * `FOR UPDATE` and `FOR NO KEY UPDATE` and with nothing else.
     *
     * Using `FOR UPDATE` here instead would prove nothing in this
     * harness: RefreshDatabase never commits, so every FK-bearing row the
     * job legitimately writes (runs, cursors, items) leaves the test's own
     * outer transaction holding `FOR KEY SHARE` on this row for the whole
     * test, and a `FOR UPDATE` probe would fail regardless of what the job
     * does.
     */
    private function connectionRowAcceptsDurableWrites(int $connectionId): bool
    {
        $probe = DB::connection(self::PROBE_CONNECTION);

        try {
            return $probe->transaction(function () use ($probe, $connectionId) {
                $rows = $probe->select(
                    'select id from firm_integrations where id = ? for key share nowait',
                    [$connectionId]
                );

                // A row that cannot be seen is not a proof of anything —
                // fail the test loudly rather than passing vacuously.
                if ($rows === []) {
                    throw new \RuntimeException(
                        'The probe session cannot see firm_integrations row '.$connectionId
                            .' — the fixture must be committed for this test to mean anything.'
                    );
                }

                return true;
            });
        } catch (QueryException) {
            // 55P03 lock_not_available — somebody holds FOR UPDATE (or FOR
            // NO KEY UPDATE) on this row, so a durable cross-session write
            // referencing it would block right now.
            return false;
        }
    }

    private function pages(int $count): array
    {
        $pages = [];

        for ($index = 0; $index < $count; $index++) {
            $pages[$index] = [[
                'external_id' => 'ext-'.$index,
                'version_token' => 'v1',
                'raw' => ['index' => $index],
            ]];
        }

        return $pages;
    }

    /**
     * Reads of the job's OWN writes must use the default connection under
     * firm context: those rows live inside RefreshDatabase's uncommitted
     * transaction, so the separate probe session cannot see them. Only the
     * lock probe needs the other session.
     */
    private function cursorRow(Firm $firm, int $connectionId): ?object
    {
        return $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_cursors')
            ->where('firm_integration_id', $connectionId)
            ->where('resource_type', 'contact')
            ->first());
    }

    private function syncItemCount(Firm $firm, int $connectionId): int
    {
        return $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_items')
            ->whereIn('sync_run_id', DB::table('integration_sync_runs')
                ->where('firm_integration_id', $connectionId)
                ->pluck('id'))
            ->count());
    }

    private function latestRunRow(Firm $firm, int $connectionId): ?object
    {
        return $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_runs')
            ->where('firm_integration_id', $connectionId)
            ->orderByDesc('id')
            ->first());
    }

    // ------------------------------------------------------------------
    // 1. The lock probe, and its own positive control
    // ------------------------------------------------------------------

    public function test_the_lock_probe_really_detects_a_held_for_update_lock(): void
    {
        // POSITIVE CONTROL. Without this, the test below could pass
        // simply because the probe never works.
        [, $connection] = $this->committedConnection();

        $this->assertTrue(
            $this->connectionRowAcceptsDurableWrites($connection->id),
            'Baseline: with nobody holding the row, the probe must succeed.'
        );

        DB::beginTransaction();

        try {
            DB::select('select id from firm_integrations where id = ? for update', [$connection->id]);

            $this->assertFalse(
                $this->connectionRowAcceptsDurableWrites($connection->id),
                'With FOR UPDATE genuinely held, the probe MUST fail — otherwise it proves nothing.'
            );
        } finally {
            DB::rollBack();
        }
    }

    public function test_no_lock_is_held_on_the_connection_row_while_a_provider_call_is_in_flight(): void
    {
        [$firm, $connection] = $this->committedConnection();
        $observations = [];

        $this->registerObservingProvider(
            $this->pages(3),
            function (int $pageIndex) use ($connection, &$observations) {
                $observations[$pageIndex] = $this->connectionRowAcceptsDurableWrites($connection->id);
            },
        );

        $this->dispatchPull($connection, $firm->id);

        $this->assertNotEmpty($observations);
        foreach ($observations as $pageIndex => $lockable) {
            $this->assertTrue(
                $lockable,
                "Page {$pageIndex}: PullSyncJob must not hold FOR UPDATE on firm_integrations across a provider call — "
                    .'that is the exact lock Checkpoint 8.1 deadlocked against.'
            );
        }
    }

    public function test_no_transaction_is_open_around_a_provider_call(): void
    {
        [$firm, $connection] = $this->committedConnection();
        $levelBefore = DB::transactionLevel();
        $observed = [];

        $this->registerObservingProvider(
            $this->pages(2),
            function (int $pageIndex) use (&$observed) {
                $observed[$pageIndex] = DB::transactionLevel();
            },
        );

        $this->dispatchPull($connection, $firm->id);

        foreach ($observed as $pageIndex => $level) {
            $this->assertSame(
                $levelBefore,
                $level,
                "Page {$pageIndex}: the job must not add a transaction level around the provider call."
            );
        }
    }

    // ------------------------------------------------------------------
    // 2. Per-page durability
    // ------------------------------------------------------------------

    public function test_pages_applied_before_a_mid_sync_failure_stay_committed(): void
    {
        [$firm, $connection] = $this->committedConnection();

        // Pages 0 and 1 succeed; the provider fails on page 2.
        $this->registerObservingProvider($this->pages(4), failOnPageIndex: 2);

        $this->dispatchPull($connection, $firm->id);

        $this->assertSame(
            2,
            $this->syncItemCount($firm, $connection->id),
            'The two pages that completed before the failure must remain committed.'
        );

        // The cursor sits at the last SUCCESSFULLY applied page, never
        // past the failure.
        $cursor = $this->cursorRow($firm, $connection->id);
        $this->assertNotNull($cursor);
        $this->assertSame(2, (int) $cursor->cursor_version, 'Exactly two advances committed.');
        $this->assertSame(CursorStatus::Failed->value, $cursor->status);

        // Failed, not PartialFailure: this fixture provider is not Plaid,
        // so its items have no local materializer and are recorded
        // Skipped, never Succeeded — and `itemsSucceeded === 0` with a
        // blocking failure is Failed by
        // determineTerminalStatus()'s pre-existing rule. The point of this
        // test is the two COMMITTED pages above, which the old
        // one-transaction-per-job design would have discarded entirely.
        $this->assertSame(
            SyncRunStatus::Failed->value,
            $this->latestRunRow($firm, $connection->id)->status
        );
    }

    public function test_every_page_advances_the_cursor_exactly_once(): void
    {
        [$firm, $connection] = $this->committedConnection();
        $this->registerObservingProvider($this->pages(3));

        $this->dispatchPull($connection, $firm->id);

        $cursor = $this->cursorRow($firm, $connection->id);
        $this->assertSame(3, (int) $cursor->cursor_version, 'Three pages, three atomic advances.');
        $this->assertSame(CursorStatus::Idle->value, $cursor->status);
        $this->assertNull($cursor->locked_by_sync_run_id, 'A completed run releases its claim.');
    }

    // ------------------------------------------------------------------
    // 3. Cursor claim ownership and its lease
    // ------------------------------------------------------------------

    public function test_a_live_claim_cannot_be_stolen_by_another_run(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Active->value])
        );
        $cursors = app(SyncCursorService::class);

        $cursor = $this->runWithFirmContext($firm, fn () => $cursors->firstOrCreate($connection, 'contact', SyncDirection::Inbound));
        $claimed = $this->runWithFirmContext($firm, fn () => $cursors->claim($cursor->id, 4242));

        $this->assertNotNull($claimed);
        $this->assertNull(
            $this->runWithFirmContext($firm, fn () => $cursors->claim($cursor->id, 5353)),
            'A cursor held by a live run must never be claimable by a second run.'
        );
    }

    public function test_a_claim_abandoned_by_a_killed_worker_becomes_takeable_only_after_its_lease_lapses(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Active->value])
        );
        $cursors = app(SyncCursorService::class);
        $leaseSeconds = (int) config('integrations.sync_cursors.claim_lease_seconds');

        $cursor = $this->runWithFirmContext($firm, fn () => $cursors->firstOrCreate($connection, 'contact', SyncDirection::Inbound));
        $this->runWithFirmContext($firm, fn () => $cursors->claim($cursor->id, 4242));

        // Just before the lease lapses: still owned. This is the property
        // that makes the lease safe — it must be impossible for a live
        // job (bounded by PullSyncJob::$timeout) to lose its cursor.
        $this->travel($leaseSeconds - 60)->seconds();
        $this->assertNull(
            $this->runWithFirmContext($firm, fn () => $cursors->claim($cursor->id, 5353)),
            'A lease must not lapse while its owner could still legitimately be working.'
        );

        // After it lapses: the abandoned claim may be taken over, which is
        // what keeps a killed worker from stalling this cursor forever.
        $this->travel(120)->seconds();
        $takenOver = $this->runWithFirmContext($firm, fn () => $cursors->claim($cursor->id, 5353));

        $this->assertNotNull($takenOver);
        $this->assertSame(5353, (int) $takenOver->locked_by_sync_run_id);
    }

    public function test_the_configured_lease_stays_larger_than_the_jobs_own_timeout(): void
    {
        // A lease shorter than the job's timeout would let two runs
        // process one cursor — the whole point of the guard above.
        $lease = (int) config('integrations.sync_cursors.claim_lease_seconds');
        $timeout = (new PullSyncJob(1, 1, 'contact'))->timeout;

        $this->assertGreaterThan(
            $timeout,
            $lease,
            'integrations.sync_cursors.claim_lease_seconds must exceed PullSyncJob::$timeout.'
        );
    }

    // ------------------------------------------------------------------
    // 4. Context hygiene (§A11)
    // ------------------------------------------------------------------

    public function test_the_session_scoped_provider_phase_leaves_no_tenant_context_behind(): void
    {
        [$firm, $connection] = $this->committedConnection();
        $this->registerObservingProvider($this->pages(2));

        // The committed-fixture factories above leave their own tenant
        // context behind on this connection (pre-existing test-harness
        // behavior, unrelated to this job). Clear it first so what is
        // asserted afterwards is the JOB's cleanup, not theirs.
        app(TenantContextService::class)->clearDatabaseTenantContext();

        $this->assertNoDatabaseTenantContext();
        $this->dispatchPull($connection, $firm->id);
        $this->assertNoDatabaseTenantContext(
            'The non-transactional provider phase sets a session-scoped setting and MUST restore it.'
        );
    }

    public function test_tenant_context_is_restored_even_when_a_page_apply_throws(): void
    {
        [$firm, $connection] = $this->committedConnection();

        // A page whose item payload cannot be JSON-encoded makes
        // applyPage() throw from inside its own transaction.
        $this->registerObservingProvider([
            0 => [[
                'external_id' => 'ext-0',
                'version_token' => 'v1',
                'raw' => ['bad' => INF],
            ]],
        ]);

        app(TenantContextService::class)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();
        $levelBefore = DB::transactionLevel();

        try {
            $this->dispatchPull($connection, $firm->id);
        } catch (\Throwable) {
            // The throw is the point; what matters is the cleanup below.
        }

        $this->assertNoDatabaseTenantContext(
            'A page-apply failure must still restore context — the finally in both context helpers.'
        );
        $this->assertSame($levelBefore, DB::transactionLevel(), 'No transaction may be left open either.');
    }

    public function test_an_unclaimable_cursor_short_circuits_before_any_provider_call(): void
    {
        [$firm, $connection] = $this->committedConnection();
        $called = 0;

        $this->registerObservingProvider($this->pages(1), function () use (&$called) {
            $called++;
        });

        // A live claim held by a different run id.
        $cursors = app(SyncCursorService::class);
        $cursor = $this->runWithFirmContext($firm, fn () => $cursors->firstOrCreate($connection, 'contact', SyncDirection::Inbound));
        $this->runWithFirmContext($firm, fn () => $cursors->claim($cursor->id, 999111));

        $this->dispatchPull($connection, $firm->id);

        $this->assertSame(0, $called, 'A job that cannot claim the cursor must never call the provider.');
    }

    public function test_an_inactive_connection_never_reaches_the_provider(): void
    {
        [$firm, $connection] = $this->committedConnection();
        $called = 0;

        $this->registerObservingProvider($this->pages(1), function () use (&$called) {
            $called++;
        });

        DB::connection(self::PROBE_CONNECTION)
            ->table('firm_integrations')
            ->where('id', $connection->id)
            ->update(['status' => ConnectionStatus::Disconnected->value]);

        $this->dispatchPull($connection, $firm->id);

        $this->assertSame(0, $called);
        $this->assertNull(
            IntegrationSyncCursor::on(self::PROBE_CONNECTION)
                ->where('firm_integration_id', $connection->id)
                ->first()?->locked_by_sync_run_id,
            'No claim may be taken for a connection that is not Active.'
        );
    }
}
