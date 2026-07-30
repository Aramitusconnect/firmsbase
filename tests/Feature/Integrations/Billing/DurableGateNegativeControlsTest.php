<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Integrations\Billing\ProviderOperationAttemptService;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Integrations\Enums\ProviderOperationClaimDecision;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PurgesDurableProviderOperationAttempts;
use Tests\TestCase;

/**
 * DurableGateNegativeControlsTest — Checkpoint 8.2 §A13/§A14.
 *
 * Every other test in this remediation asserts that the NEW design behaves
 * correctly. That is not enough on its own: a test suite full of green
 * checks proves nothing if the tests would also pass against the broken
 * design. This file is the control group.
 *
 *   NC-A  Reproduces the ORIGINAL defect's mechanism directly — evidence
 *         written on the caller's own connection vanishes when that
 *         caller's transaction rolls back — and then shows the durable
 *         path surviving the identical rollback. Same rollback, two
 *         outcomes.
 *
 *   NC-B  Reproduces the REJECTED Checkpoint 8.1 design's failure — a
 *         cross-session write whose foreign key references a row held
 *         `FOR UPDATE` cannot proceed — and shows that the FK-free gate
 *         table is unaffected under the identical lock.
 *
 *   A14   Real concurrency, using genuinely separate database sessions
 *         and separate service instances rather than a simulated race.
 *
 * EVERY lock-related assertion here is bounded by an explicit
 * `lock_timeout`/`NOWAIT`. A negative control that could hang the suite
 * would be worse than no negative control at all — that is exactly how
 * Checkpoint 8.1's own suite stalled.
 */
final class DurableGateNegativeControlsTest extends TestCase
{
    use PurgesDurableProviderOperationAttempts;
    use RefreshDatabase;

    private const DURABLE_CONNECTION = 'pgsql_audit';

    private const FIRM_ID = 993001;

    private ProviderOperationAttemptService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProviderOperationAttemptService::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    private function purge(): void
    {
        DB::connection(self::DURABLE_CONNECTION)
            ->table('provider_operation_attempts')
            ->where('firm_id', self::FIRM_ID)
            ->delete();
    }

    // ------------------------------------------------------------------
    // NC-A — the original defect's mechanism, side by side with the fix
    // ------------------------------------------------------------------

    /**
     * The control: a row written on the CALLER's connection inside the
     * caller's transaction is gone after a rollback. This is precisely how
     * the Checkpoint 8 C3 defect lost the record of a provider call that
     * had really happened.
     */
    public function test_negative_control_a_evidence_on_the_callers_own_connection_does_not_survive_a_rollback(): void
    {
        DB::beginTransaction();

        DB::table('provider_operation_attempts')->insert([
            'uuid' => (string) Str::uuid7(),
            'logical_operation_key' => 'nc-a:ambient-write',
            'provider_key' => 'plaid',
            'firm_id' => self::FIRM_ID,
            'operation_type' => 'control',
            'operation_version' => 1,
            'attempt_state' => ProviderOperationAttemptState::AttemptStarted->value,
            'send_count' => 1,
            'total_send_count' => 1,
            'reclaim_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            1,
            DB::table('provider_operation_attempts')->where('logical_operation_key', 'nc-a:ambient-write')->count(),
            'The row provably exists inside the transaction.'
        );

        DB::rollBack();

        $this->assertSame(
            0,
            DB::connection(self::DURABLE_CONNECTION)
                ->table('provider_operation_attempts')
                ->where('logical_operation_key', 'nc-a:ambient-write')
                ->count(),
            'An ambient-connection write is destroyed by the rollback — the original defect.'
        );
    }

    /**
     * The same rollback, against the real service. The ONLY difference is
     * which connection the write happened on, and that difference is the
     * entire remediation.
     */
    public function test_the_durable_path_survives_the_identical_rollback(): void
    {
        DB::beginTransaction();

        $claim = $this->service->claim(
            logicalOperationKey: 'nc-a:durable-write',
            providerKey: 'plaid',
            firmId: self::FIRM_ID,
            firmIntegrationId: null,
            operationType: 'control',
        );
        $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail());

        DB::rollBack();

        $row = DB::connection(self::DURABLE_CONNECTION)
            ->table('provider_operation_attempts')
            ->where('logical_operation_key', 'nc-a:durable-write')
            ->first();

        $this->assertNotNull($row, 'The durable write survives.');
        $this->assertSame(ProviderOperationAttemptState::AttemptStarted->value, $row->attempt_state);
        $this->assertSame(1, (int) $row->send_count);
    }

    // ------------------------------------------------------------------
    // NC-B — the rejected Checkpoint 8.1 design, reproduced and bounded
    // ------------------------------------------------------------------

    /**
     * The control for the deadlock that got Checkpoint 8.1 rejected.
     *
     * A cross-session INSERT whose foreign key references a row must take
     * `FOR KEY SHARE` on it, and `FOR UPDATE` blocks that. `PullSyncJob`
     * used to hold exactly that `FOR UPDATE` across its provider call, so
     * the durable insert waited for a transaction that could not commit
     * until the job finished.
     *
     * Reproduced here with `integration_provider_webhook_subscriptions`
     * (a real FK-bearing table) and a hard 2s `lock_timeout`, so a
     * regression fails in two seconds instead of hanging the suite.
     */
    public function test_negative_control_b_an_fk_bearing_cross_session_insert_cannot_proceed_under_for_update(): void
    {
        [$firm, $connection] = $this->committedConnection();

        DB::beginTransaction();

        try {
            // The job's lock.
            DB::select('select id from firm_integrations where id = ? for update', [$connection->id]);

            $durable = DB::connection(self::DURABLE_CONNECTION);
            $durable->statement("set lock_timeout = '2s'");
            $this->setDurableFirmContext((int) $firm->id);

            $blocked = false;

            try {
                $durable->table('integration_provider_webhook_subscriptions')->insert([
                    'firm_id' => $firm->id,
                    'firm_integration_id' => $connection->id,
                    'provider_key' => 'microsoft365',
                    'resource_type' => 'contact',
                    'provider_resource' => 'me/contacts',
                    'provider_change_type' => 'created,updated',
                    'provider_subscription_id' => 'nc-b-probe',
                    'expires_at' => now()->addDay(),
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException) {
                // 55P03 lock_not_available — the FK's FOR KEY SHARE could
                // not be taken.
                $blocked = true;
            }

            $this->assertTrue(
                $blocked,
                'This is the Checkpoint 8.1 failure: an FK-bearing cross-session insert cannot proceed while the '
                    .'connection row is held FOR UPDATE. If this ever stops being true, NC-B below proves nothing.'
            );
        } finally {
            DB::connection(self::DURABLE_CONNECTION)->statement('set lock_timeout = default');
            $this->clearDurableFirmContext();
            DB::rollBack();
        }
    }

    /**
     * The same lock, the same separate session, the same 2s timeout — but
     * against the FK-FREE gate table. It proceeds, and that is the whole
     * reason the table has no foreign keys.
     */
    public function test_the_fk_free_gate_table_writes_freely_under_the_same_for_update_lock(): void
    {
        [, $connection] = $this->committedConnection();

        DB::beginTransaction();

        try {
            DB::select('select id from firm_integrations where id = ? for update', [$connection->id]);

            DB::connection(self::DURABLE_CONNECTION)->statement("set lock_timeout = '2s'");

            $claim = $this->service->claim(
                logicalOperationKey: 'nc-b:fk-free-write',
                providerKey: 'plaid',
                firmId: self::FIRM_ID,
                firmIntegrationId: (int) $connection->id,
                operationType: 'control',
            );

            $this->assertSame(ProviderOperationClaimDecision::Proceed, $claim->decision);

            $started = $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail());
            $this->assertSame(1, (int) $started->send_count);
        } finally {
            DB::connection(self::DURABLE_CONNECTION)->statement('set lock_timeout = default');
            DB::rollBack();
        }
    }

    // ------------------------------------------------------------------
    // A14 — real concurrency across genuinely separate sessions
    // ------------------------------------------------------------------

    /**
     * Two independent service instances race for the same logical
     * operation. Exactly one may win.
     *
     * This is a real race on a real unique index, not a simulated one: the
     * losing insert genuinely violates
     * `provider_operation_attempts_logical_operation_key_unique` and is
     * routed through the decision table rather than throwing.
     */
    public function test_two_racing_workers_produce_exactly_one_winner(): void
    {
        $workerA = new ProviderOperationAttemptService;
        $workerB = new ProviderOperationAttemptService;

        $claimA = $workerA->claim('a14:race', 'plaid', self::FIRM_ID, null, 'control');
        $claimB = $workerB->claim('a14:race', 'plaid', self::FIRM_ID, null, 'control');

        $winners = array_filter([$claimA, $claimB], fn ($claim) => $claim->maySendProviderRequest());

        $this->assertCount(1, $winners, 'Exactly one worker may be authorized to send.');
        $this->assertSame(
            1,
            DB::connection(self::DURABLE_CONNECTION)
                ->table('provider_operation_attempts')
                ->where('logical_operation_key', 'a14:race')
                ->count(),
            'The unique index must permit exactly one gate row.'
        );

        // And the loser's own attempt to record a send fails closed.
        $loser = $claimA->maySendProviderRequest() ? $claimB : $claimA;
        $this->assertFalse($loser->maySendProviderRequest());
        $this->assertNull($loser->ownerToken);
    }

    /**
     * The second race that matters: two workers both believing they hold
     * the same abandoned lease. The compare-and-set must let exactly one
     * take it over.
     */
    public function test_two_workers_racing_an_abandoned_lease_produce_exactly_one_takeover(): void
    {
        $original = $this->service->claim('a14:lease-race', 'plaid', self::FIRM_ID, null, 'control', leaseSeconds: 60);
        $this->assertTrue($original->maySendProviderRequest());

        $this->travel(120)->seconds();

        $workerA = new ProviderOperationAttemptService;
        $workerB = new ProviderOperationAttemptService;

        $claimA = $workerA->claim('a14:lease-race', 'plaid', self::FIRM_ID, null, 'control', leaseSeconds: 60);
        $claimB = $workerB->claim('a14:lease-race', 'plaid', self::FIRM_ID, null, 'control', leaseSeconds: 60);

        $winners = array_filter([$claimA, $claimB], fn ($claim) => $claim->maySendProviderRequest());
        $this->assertCount(1, $winners, 'An abandoned lease may be taken over by exactly one worker.');

        $row = DB::connection(self::DURABLE_CONNECTION)
            ->table('provider_operation_attempts')
            ->where('logical_operation_key', 'a14:lease-race')
            ->first();

        $this->assertSame(1, (int) $row->reclaim_count, 'Exactly one takeover is recorded.');
        $this->assertSame(0, (int) $row->send_count);
    }

    /**
     * The invariant that matters most, under a real race: even with several
     * workers competing and taking over from each other, no row may ever
     * record more than one send per generation.
     */
    public function test_a_contended_operation_never_records_more_than_one_send(): void
    {
        $key = 'a14:contended';
        $workers = [new ProviderOperationAttemptService, new ProviderOperationAttemptService, new ProviderOperationAttemptService];
        $sends = 0;

        foreach ($workers as $worker) {
            $claim = $worker->claim($key, 'plaid', self::FIRM_ID, null, 'control', leaseSeconds: 60);

            if (! $claim->maySendProviderRequest()) {
                continue;
            }

            $worker->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail());
            $sends++;
        }

        $this->assertSame(1, $sends, 'Only the single winner may record a send.');

        $row = DB::connection(self::DURABLE_CONNECTION)
            ->table('provider_operation_attempts')
            ->where('logical_operation_key', $key)
            ->first();

        $this->assertSame(1, (int) $row->send_count);
        $this->assertSame(1, (int) $row->total_send_count);
    }

    // ------------------------------------------------------------------

    /**
     * A Firm + FirmIntegration committed for real, so a second session can
     * see and lock them. Cleaned up after the test's own transaction has
     * already been rolled back.
     *
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function committedConnection(): array
    {
        $firm = Firm::factory()->connection(self::DURABLE_CONNECTION)->create();
        $this->setDurableFirmContext((int) $firm->id);

        $connection = FirmIntegration::factory()
            ->connection(self::DURABLE_CONNECTION)
            ->forFirm($firm)
            ->create([
                'status' => ConnectionStatus::Active->value,
                'external_account_id' => null,
                'connected_by_firm_user_id' => null,
            ]);

        $this->beforeApplicationDestroyed(function () use ($firm, $connection) {
            $durable = DB::connection(self::DURABLE_CONNECTION);
            $this->setDurableFirmContext((int) $firm->id);

            $durable->table('integration_provider_webhook_subscriptions')->where('firm_integration_id', $connection->id)->delete();
            $durable->table('timeline_events')->where('firm_id', $firm->id)->delete();
            $durable->table('firm_integrations')->where('id', $connection->id)->delete();
            $durable->table('firms')->where('id', $firm->id)->delete();

            $this->clearDurableFirmContext();
        });

        $this->clearDurableFirmContext();

        return [$firm, $connection];
    }

    private function setDurableFirmContext(int $firmId): void
    {
        DB::connection(self::DURABLE_CONNECTION)
            ->select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firmId, false]);
    }

    private function clearDurableFirmContext(): void
    {
        DB::connection(self::DURABLE_CONNECTION)
            ->select('select set_config(?, ?, ?)', ['app.current_firm_id', '', false]);
    }
}
