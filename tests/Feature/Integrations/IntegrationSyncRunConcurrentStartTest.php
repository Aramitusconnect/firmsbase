<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Exceptions\SyncRunAlreadyInProgressException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\SyncRunService;
use App\Models\Firm;
use App\Services\TimelineEventRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IntegrationSyncRunConcurrentStartTest — Checkpoint 13 (frozen-test-
 * closure-plan.md §4; agent-13h §4 concurrency proofs, domain 9). Proves
 * SyncRunService::startRun()'s Layer-1 concurrency defence — the partial
 * unique index `integration_sync_runs_one_active_per_scope`
 * (firm_id, firm_integration_id, resource_type, sync_direction) WHERE
 * status IN ('pending','running') — against TWO REAL, separate physical
 * database connections: exactly one start succeeds, and a second start for
 * a scope already active on the other connection gets the typed
 * SyncRunAlreadyInProgressException.
 *
 * Two complementary proofs:
 *  1. Genuine simultaneous contention — while connection A holds an
 *     uncommitted `pending` row for the scope, connection B's insert for
 *     the SAME scope BLOCKS on the partial unique index and (under a short
 *     lock_timeout) fails deterministically, never silently creating a
 *     second active run. No sleep()/usleep().
 *  2. The real production path — once a genuinely separate physical
 *     connection has committed an active run for the scope,
 *     SyncRunService::startRun() (the real, unmodified method) rejects a
 *     second start with SyncRunAlreadyInProgressException carrying the
 *     already-existing run.
 *
 * Deliberately does NOT use RefreshDatabase — a genuine second physical
 * connection can only see committed rows (identical rationale to
 * IntegrationOutboxConcurrentClaimTest / SyncRetryPollJobTest). Fixtures
 * are real committed rows, deleted in tearDown() via cascadeOnDelete()
 * from `firms`.
 */
class IntegrationSyncRunConcurrentStartTest extends TestCase
{
    /** @var int[] */
    private array $createdFirmIds = [];

    private const RESOURCE_TYPE = 'contact';

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

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    /**
     * Insert a `pending` run for the canonical scope on the given
     * connection handle — mirrors the columns SyncRunService::startRun()
     * itself writes (a manual, inbound, initial run). Returns the new id.
     */
    private function insertPendingRun(string $connectionName, Firm $firm, FirmIntegration $connection): ?int
    {
        $row = DB::connection($connectionName)->selectOne(
            'INSERT INTO integration_sync_runs '.
            '(firm_id, firm_integration_id, resource_type, sync_direction, run_type, trigger_source, status, created_at, updated_at) '.
            "VALUES (?, ?, ?, 'inbound', 'manual', 'manual', 'pending', now(), now()) RETURNING id",
            [$firm->id, $connection->id, self::RESOURCE_TYPE]
        );

        return $row === null ? null : (int) $row->id;
    }

    // ------------------------------------------------------------
    // Proof 1: genuine simultaneous contention at the partial unique index.
    // ------------------------------------------------------------

    public function test_two_concurrent_starts_for_the_same_scope_contend_at_the_partial_unique_index(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        config(['database.connections.worker_b' => config('database.connections.pgsql')]);
        DB::purge('worker_b');

        $lockTimeoutMessage = null;
        $winningRunId = null;

        try {
            // --- Connection A (default) --------------------------------
            DB::beginTransaction();
            DB::select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, false]);

            $winningRunId = $this->insertPendingRun('pgsql', $firm, $connection);
            $this->assertNotNull($winningRunId, 'Connection A must successfully insert the first pending run for the scope.');

            // A's transaction stays open (uncommitted) — the partial-unique
            // index entry for this scope is held while B races the same scope.

            // --- Connection B (worker_b) -------------------------------
            DB::connection('worker_b')->beginTransaction();
            DB::connection('worker_b')->select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, false]);
            DB::connection('worker_b')->statement("SET LOCAL lock_timeout = '200ms'");

            try {
                $this->insertPendingRun('worker_b', $firm, $connection);

                $this->fail('Connection B\'s insert for the same active scope must block on the partial unique index and time out — it must never create a second active run while A\'s transaction is still open.');
            } catch (QueryException $e) {
                $lockTimeoutMessage = strtolower($e->getMessage());
            }

            $this->assertStringContainsString(
                'lock timeout',
                $lockTimeoutMessage,
                'Connection B\'s insert must fail specifically with PostgreSQL\'s lock_timeout error, proving the partial unique index genuinely serialized the two concurrent same-scope starts — not silently admitted both.'
            );

            DB::connection('worker_b')->rollBack();
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

        // Exactly one active run for the scope exists after the dust settles.
        $activeCount = $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_runs')
            ->where('firm_integration_id', $connection->id)
            ->where('resource_type', self::RESOURCE_TYPE)
            ->where('sync_direction', 'inbound')
            ->whereIn('status', ['pending', 'running'])
            ->count());

        $this->assertSame(1, $activeCount, 'Exactly one active run must exist — connection A\'s, and only A\'s.');
    }

    // ------------------------------------------------------------
    // Proof 2: the real startRun() rejects a second start for a scope
    // already active on ANOTHER physical connection, with the typed
    // exception carrying the existing run.
    // ------------------------------------------------------------

    public function test_a_second_start_against_a_scope_active_on_another_connection_throws_the_typed_exception(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        config(['database.connections.worker_b' => config('database.connections.pgsql')]);
        DB::purge('worker_b');

        // Connection worker_b creates AND COMMITS the first active run for
        // the scope — a genuinely separate physical connection, not the one
        // startRun() runs on.
        DB::connection('worker_b')->beginTransaction();
        DB::connection('worker_b')->select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, false]);
        $existingRunId = $this->insertPendingRun('worker_b', $firm, $connection);
        DB::connection('worker_b')->commit();
        DB::connection('worker_b')->select('select set_config(?, ?, ?)', ['app.current_firm_id', '', false]);

        $this->assertNotNull($existingRunId);

        // The REAL, unmodified startRun() on the default connection must now
        // hit the committed conflicting row -> UniqueConstraintViolationException
        // -> typed SyncRunAlreadyInProgressException.
        $service = new SyncRunService(new TimelineEventRecorder);

        $thrown = null;
        try {
            $this->runWithFirmContext($firm, fn () => $service->startRun(
                $connection,
                self::RESOURCE_TYPE,
                SyncDirection::Inbound,
                SyncTriggerSource::Manual,
            ));
            $this->fail('A second startRun() for a scope that already has a committed active run must throw SyncRunAlreadyInProgressException.');
        } catch (SyncRunAlreadyInProgressException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertSame(
            $existingRunId,
            $thrown->existingRun->id,
            'The typed exception must carry the ALREADY-existing run (the one worker_b committed), never a newly-created one.'
        );

        // And no second active run was created by the losing start.
        $activeCount = $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_runs')
            ->where('firm_integration_id', $connection->id)
            ->where('resource_type', self::RESOURCE_TYPE)
            ->where('sync_direction', 'inbound')
            ->whereIn('status', ['pending', 'running'])
            ->count());

        $this->assertSame(1, $activeCount, 'Exactly one active run must exist — the rejected start created nothing.');
    }
}
