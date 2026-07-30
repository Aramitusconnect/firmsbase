<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Integrations\Billing\ProviderOperationAttemptService;
use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Integrations\Enums\ProviderOperationClaimDecision;
use App\Integrations\Exceptions\ProviderOperationOwnershipLostException;
use App\Integrations\Exceptions\ProviderOperationTenantMismatchException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderOperationAttempt;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PurgesDurableProviderOperationAttempts;
use Tests\TestCase;

/**
 * ProviderOperationAttemptServiceTest — Checkpoint 8.2 §A4. Proves the
 * durable at-most-once gate: that permission to call a provider is
 * granted exactly once per logical operation, that the record of a sent
 * request survives the caller's transaction rolling back, and that no
 * automated path can ever resend after `attempt_started`.
 *
 * WHY THIS TEST NEEDS NO "DURABLE FIXTURES". Checkpoint 8.1 required an
 * elaborate CreatesDurableBillingFixtures helper because its durable
 * table carried foreign keys, so every referenced firm/connection had to
 * be committed too — the same coupling that deadlocked in production.
 * This table has no foreign keys by design, so `firm_id` and
 * `firm_integration_id` are ordinary integers here. That the tests get
 * simpler is not a convenience; it is direct evidence that the
 * cross-session coupling is gone.
 *
 * Rows written on the durable connection are really committed, so they
 * are NOT rolled back by RefreshDatabase. `tearDown()` deletes them
 * explicitly. That delete cannot block: nothing in this suite writes
 * this table on the ambient connection, and the table has no foreign
 * keys for another session's lock to propagate through — precisely the
 * failure that made Checkpoint 8.1's suite hang.
 */
class ProviderOperationAttemptServiceTest extends TestCase
{
    use PurgesDurableProviderOperationAttempts;
    use RefreshDatabase;

    private const DURABLE_CONNECTION = 'pgsql_audit';

    /** Arbitrary, deliberately non-existent firm ids — no FKs exist. */
    private const FIRM_ID = 991001;

    private const OTHER_FIRM_ID = 991002;

    private const CONNECTION_ID = 771001;

    private ProviderOperationAttemptService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProviderOperationAttemptService::class);
        $this->purgeDurableRows();
    }

    protected function tearDown(): void
    {
        $this->purgeDurableRows();
        parent::tearDown();
    }

    private function purgeDurableRows(): void
    {
        DB::connection(self::DURABLE_CONNECTION)
            ->table('provider_operation_attempts')
            ->whereIn('firm_id', [self::FIRM_ID, self::OTHER_FIRM_ID])
            ->delete();
    }

    private function claim(string $key = 'plaid_pull:test:page-1', ?int $firmId = null, ?int $leaseSeconds = null)
    {
        return $this->service->claim(
            logicalOperationKey: $key,
            providerKey: 'plaid',
            firmId: $firmId ?? self::FIRM_ID,
            firmIntegrationId: self::CONNECTION_ID,
            operationType: 'transactions_sync',
            leaseSeconds: $leaseSeconds,
        );
    }

    private function row(string $key): ProviderOperationAttempt
    {
        $attempt = ProviderOperationAttempt::on(self::DURABLE_CONNECTION)
            ->where('logical_operation_key', $key)
            ->first();

        $this->assertNotNull($attempt, "Expected a durable attempt row for {$key}.");

        return $attempt;
    }

    // ------------------------------------------------------------------
    // claim() — the single-winner gate
    // ------------------------------------------------------------------

    public function test_a_first_claim_proceeds_and_commits_a_claimed_row_that_has_never_been_sent(): void
    {
        $claim = $this->claim();

        $this->assertSame(ProviderOperationClaimDecision::Proceed, $claim->decision);
        $this->assertTrue($claim->maySendProviderRequest());
        $this->assertNotEmpty($claim->ownerTokenOrFail());

        $row = $this->row('plaid_pull:test:page-1');
        $this->assertSame(ProviderOperationAttemptState::Claimed, $row->attempt_state);
        $this->assertSame(0, (int) $row->send_count);
        $this->assertSame(0, (int) $row->reclaim_count);
        $this->assertSame(self::FIRM_ID, (int) $row->firm_id);
        $this->assertNotNull($row->lease_expires_at);
    }

    public function test_a_second_claim_while_the_first_lease_is_live_is_refused_not_granted(): void
    {
        $first = $this->claim();
        $second = $this->claim();

        $this->assertSame(ProviderOperationClaimDecision::Proceed, $first->decision);
        $this->assertSame(ProviderOperationClaimDecision::InFlightElsewhere, $second->decision);
        $this->assertFalse($second->maySendProviderRequest());
        $this->assertNull($second->ownerToken);

        // Exactly one row, still un-sent — no duplicate gate row was minted.
        $this->assertSame(1, DB::connection(self::DURABLE_CONNECTION)
            ->table('provider_operation_attempts')
            ->where('logical_operation_key', 'plaid_pull:test:page-1')
            ->count());
    }

    public function test_a_claim_for_a_key_recorded_against_another_firm_is_refused_outright(): void
    {
        $this->claim(firmId: self::FIRM_ID);

        $this->expectException(ProviderOperationTenantMismatchException::class);

        $this->claim(firmId: self::OTHER_FIRM_ID);
    }

    // ------------------------------------------------------------------
    // markAttemptStarted() — at most once, ever
    // ------------------------------------------------------------------

    public function test_marking_the_attempt_started_records_the_send_durably_before_the_call(): void
    {
        $claim = $this->claim();

        $started = $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail(), 'req-abc');

        $this->assertSame(ProviderOperationAttemptState::AttemptStarted, $started->attempt_state);
        $this->assertSame(1, (int) $started->send_count);
        $this->assertSame('req-abc', $started->provider_request_reference);
        $this->assertNotNull($started->provider_started_at);
    }

    public function test_a_second_mark_attempt_started_for_the_same_operation_is_impossible(): void
    {
        $claim = $this->claim();
        $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail());

        try {
            $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail());
            $this->fail('A second attempt_started transition must not be permitted.');
        } catch (ProviderOperationOwnershipLostException $e) {
            $this->assertSame('attempt_started', $e->attemptedTransition);
        }

        // send_count is the database-level proof: never more than one.
        $this->assertSame(1, (int) $this->row('plaid_pull:test:page-1')->send_count);
    }

    public function test_a_worker_whose_lease_was_taken_over_cannot_record_a_send(): void
    {
        $abandoned = $this->claim(leaseSeconds: 60);

        $this->travel(120)->seconds();

        // A second worker legitimately reclaims the provably un-sent operation.
        $reclaimed = $this->claim(leaseSeconds: 60);
        $this->assertSame(ProviderOperationClaimDecision::Proceed, $reclaimed->decision);
        $this->assertSame(1, (int) $this->row('plaid_pull:test:page-1')->reclaim_count);

        // The original worker coming back to life must fail closed.
        $this->expectException(ProviderOperationOwnershipLostException::class);

        $this->service->markAttemptStarted($abandoned->attempt, $abandoned->ownerTokenOrFail());
    }

    // ------------------------------------------------------------------
    // The C3 defect: durability across the caller's rollback
    // ------------------------------------------------------------------

    public function test_the_record_of_a_sent_request_survives_the_callers_transaction_rolling_back(): void
    {
        DB::beginTransaction();

        $claim = $this->claim();
        $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail(), 'req-rollback');

        DB::rollBack();

        // The gate row is still there, and still says the request left
        // the process. This is exactly what Checkpoint 8's C3 defect
        // could not do.
        $row = $this->row('plaid_pull:test:page-1');
        $this->assertSame(ProviderOperationAttemptState::AttemptStarted, $row->attempt_state);
        $this->assertSame(1, (int) $row->send_count);
    }

    public function test_after_a_rollback_a_retry_of_the_same_operation_is_never_granted_permission_to_send(): void
    {
        DB::beginTransaction();
        $claim = $this->claim(leaseSeconds: 60);
        $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail());
        DB::rollBack();

        // Same job, same deterministic key, retried after the worker died.
        $this->travel(120)->seconds();
        $retry = $this->claim(leaseSeconds: 60);

        $this->assertFalse($retry->maySendProviderRequest());
        $this->assertSame(ProviderOperationClaimDecision::ReconciliationRequired, $retry->decision);
        $this->assertSame(
            ProviderOperationAttemptState::ProviderOutcomeUncertain,
            $this->row('plaid_pull:test:page-1')->attempt_state
        );
        $this->assertSame(1, (int) $this->row('plaid_pull:test:page-1')->send_count);
    }

    public function test_a_retry_while_the_in_flight_lease_is_still_live_is_refused_rather_than_escalated(): void
    {
        $claim = $this->claim(leaseSeconds: 600);
        $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail());

        $retry = $this->claim(leaseSeconds: 600);

        $this->assertSame(ProviderOperationClaimDecision::InFlightElsewhere, $retry->decision);
        $this->assertSame(
            ProviderOperationAttemptState::AttemptStarted,
            $this->row('plaid_pull:test:page-1')->attempt_state
        );
    }

    // ------------------------------------------------------------------
    // Outcomes and resumption
    // ------------------------------------------------------------------

    public function test_a_definite_provider_rejection_is_the_one_post_send_state_that_may_be_retried(): void
    {
        $claim = $this->claim(leaseSeconds: 60);
        $started = $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail());
        $this->service->recordProviderRejected($started, $claim->ownerTokenOrFail(), 'http_401_before_billing');

        $retry = $this->claim(leaseSeconds: 60);

        $this->assertSame(ProviderOperationClaimDecision::Proceed, $retry->decision);

        $row = $this->row('plaid_pull:test:page-1');
        $this->assertSame(ProviderOperationAttemptState::Claimed, $row->attempt_state);
        $this->assertSame(1, (int) $row->reclaim_count);

        // A new attempt generation begins: the current-generation
        // counter resets, but the monotonic history is preserved.
        $this->assertSame(0, (int) $row->send_count);
        $this->assertSame(1, (int) $row->total_send_count);

        // And the fresh generation may send exactly once more.
        $this->service->markAttemptStarted($row, $retry->ownerTokenOrFail());
        $after = $this->row('plaid_pull:test:page-1');
        $this->assertSame(1, (int) $after->send_count);
        $this->assertSame(2, (int) $after->total_send_count);
    }

    public function test_a_local_failure_after_provider_success_resumes_without_calling_the_provider_again(): void
    {
        $claim = $this->claim(leaseSeconds: 60);
        $started = $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail());
        $succeeded = $this->service->recordProviderSucceeded(
            $started,
            $claim->ownerTokenOrFail(),
            providerOutcome: 'ok',
            billableClassification: 'billable',
            redactedResultMetadata: '{"transactions":12}',
            resultChecksum: 'sha256:abc',
        );
        $this->service->markLocalProcessingFailed(
            $succeeded,
            $claim->ownerTokenOrFail(),
            'materializer_threw',
            localProcessingState: 'page-1',
        );

        // No waiting for a lease to lapse: the worker that gave up
        // released it, so the very next retry resumes immediately.
        $resume = $this->claim(leaseSeconds: 60);

        $this->assertSame(ProviderOperationClaimDecision::ResumeLocalProcessing, $resume->decision);
        $this->assertFalse($resume->maySendProviderRequest());
        $this->assertTrue($resume->decision->shouldResumeLocalProcessing());

        // The durable provider evidence is intact for the resumed run.
        $this->assertSame('{"transactions":12}', $resume->attempt->redacted_result_metadata);
        $this->assertSame('sha256:abc', $resume->attempt->result_checksum);
        $this->assertSame('page-1', $resume->attempt->local_processing_state);
        $this->assertSame(1, (int) $resume->attempt->send_count);

        // And the resumed run can complete from that state.
        $complete = $this->service->markLocalProcessingComplete($resume->attempt, $resume->ownerTokenOrFail());
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingComplete, $complete->attempt_state);
        $this->assertNotNull($complete->finalized_at);
    }

    public function test_a_duplicate_delivery_of_a_completed_operation_is_an_idempotent_no_op(): void
    {
        $claim = $this->claim();
        $started = $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail());
        $succeeded = $this->service->recordProviderSucceeded($started, $claim->ownerTokenOrFail());
        $this->service->markLocalProcessingComplete($succeeded, $claim->ownerTokenOrFail());

        $duplicate = $this->claim();

        $this->assertSame(ProviderOperationClaimDecision::AlreadyComplete, $duplicate->decision);
        $this->assertFalse($duplicate->maySendProviderRequest());
        $this->assertSame(1, (int) $this->row('plaid_pull:test:page-1')->send_count);
    }

    public function test_an_uncertain_provider_outcome_escalates_and_never_becomes_sendable_again(): void
    {
        $claim = $this->claim();
        $started = $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail());
        $uncertain = $this->service->recordProviderOutcomeUncertain($started, $claim->ownerTokenOrFail(), 'read_timeout');

        $this->assertSame(ProviderOperationClaimDecision::ReconciliationRequired, $this->claim()->decision);

        $reconciling = $this->service->markReconciliationRequired($uncertain, 'timeout_needs_provider_side_check');
        $this->assertSame(ProviderOperationAttemptState::ReconciliationRequired, $reconciling->attempt_state);
        $this->assertSame('timeout_needs_provider_side_check', $reconciling->reconciliation_reason);

        // Still not sendable, even after time passes.
        $this->travel(3600)->seconds();
        $this->assertSame(ProviderOperationClaimDecision::ReconciliationRequired, $this->claim()->decision);
    }

    // ------------------------------------------------------------------
    // Operator resolution — the only exit from reconciliation
    // ------------------------------------------------------------------

    public function test_an_operator_may_settle_a_reconciliation_as_complete(): void
    {
        $reconciling = $this->reconciliationRequiredRow();

        $resolved = $this->service->resolveReconciliation(
            $reconciling,
            ProviderOperationAttemptState::LocalProcessingComplete,
            'confirmed_billed_on_provider_dashboard',
            resolvedByUserId: 4242,
        );

        $this->assertSame(ProviderOperationAttemptState::LocalProcessingComplete, $resolved->attempt_state);
        $this->assertStringContainsString('operator_resolved:', (string) $resolved->state_reason);
        $this->assertStringContainsString('user_4242', (string) $resolved->state_reason);
        $this->assertNotNull($resolved->finalized_at);
        $this->assertSame(ProviderOperationClaimDecision::AlreadyComplete, $this->claim()->decision);
    }

    public function test_an_operator_decision_is_the_only_way_a_sent_operation_becomes_sendable_again(): void
    {
        $reconciling = $this->reconciliationRequiredRow();

        // Before the operator acts, no amount of retrying or waiting
        // produces permission to send.
        $this->travel(86400)->seconds();
        $this->assertSame(ProviderOperationClaimDecision::ReconciliationRequired, $this->claim()->decision);

        $this->service->resolveReconciliation(
            $reconciling,
            ProviderOperationAttemptState::RetryAllowed,
            'confirmed_provider_never_received_request',
            resolvedByUserId: 4242,
        );

        $retry = $this->claim();

        $this->assertSame(ProviderOperationClaimDecision::Proceed, $retry->decision);

        $row = $this->row('plaid_pull:test:page-1');
        $this->assertSame(0, (int) $row->send_count, 'A new generation must start un-sent.');
        $this->assertSame(1, (int) $row->total_send_count, 'The earlier send must remain on the record.');
        $this->assertStringContainsString('reclaimed_from_retry_allowed', (string) $row->state_reason);
    }

    public function test_a_reconciliation_may_not_be_resolved_into_an_arbitrary_state(): void
    {
        $reconciling = $this->reconciliationRequiredRow();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->resolveReconciliation(
            $reconciling,
            ProviderOperationAttemptState::ProviderSucceeded,
            'not_a_legal_resolution',
        );
    }

    private function reconciliationRequiredRow(): ProviderOperationAttempt
    {
        $claim = $this->claim();
        $started = $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail());
        $uncertain = $this->service->recordProviderOutcomeUncertain($started, $claim->ownerTokenOrFail(), 'read_timeout');

        return $this->service->markReconciliationRequired($uncertain, 'needs_provider_side_check');
    }

    // ------------------------------------------------------------------
    // Lease sweeper
    // ------------------------------------------------------------------

    public function test_the_sweeper_frees_un_sent_claims_and_quarantines_in_flight_ones(): void
    {
        $unsent = $this->claim(key: 'op:unsent', leaseSeconds: 60);

        $inFlight = $this->claim(key: 'op:in-flight', leaseSeconds: 60);
        $this->service->markAttemptStarted($inFlight->attempt, $inFlight->ownerTokenOrFail());

        $live = $this->claim(key: 'op:live', leaseSeconds: 86400);

        $this->travel(120)->seconds();

        $counts = $this->service->sweepExpiredLeases();

        $this->assertSame(1, $counts['retry_allowed']);
        $this->assertSame(1, $counts['outcome_uncertain']);

        $this->assertSame(ProviderOperationAttemptState::RetryAllowed, $this->row('op:unsent')->attempt_state);
        $this->assertSame(ProviderOperationAttemptState::ProviderOutcomeUncertain, $this->row('op:in-flight')->attempt_state);
        $this->assertSame(ProviderOperationAttemptState::Claimed, $this->row('op:live')->attempt_state);

        // The swept-out owner token is void — the abandoned worker
        // cannot come back and send.
        try {
            $this->service->markAttemptStarted($unsent->attempt, $unsent->ownerTokenOrFail());
            $this->fail('A swept-out owner token must not be able to record a send.');
        } catch (ProviderOperationOwnershipLostException) {
            $this->assertSame(0, (int) $this->row('op:unsent')->send_count);
        }

        // The still-live claim keeps its own valid ownership.
        $stillOwned = $this->service->markAttemptStarted($live->attempt, $live->ownerTokenOrFail());
        $this->assertSame(1, (int) $stillOwned->send_count);
    }

    public function test_no_row_ever_records_more_than_one_send_per_generation_across_a_full_churn_cycle(): void
    {
        // Drive one logical operation through every transition this
        // service can apply, then assert the database-level invariant.
        $rejected = $this->claim(key: 'op:churn-a', leaseSeconds: 60);
        $startedA = $this->service->markAttemptStarted($rejected->attempt, $rejected->ownerTokenOrFail());
        $this->service->recordProviderRejected($startedA, $rejected->ownerTokenOrFail(), 'http_429');
        $retryA = $this->claim(key: 'op:churn-a', leaseSeconds: 60);
        $this->service->markAttemptStarted($retryA->attempt, $retryA->ownerTokenOrFail());

        $abandoned = $this->claim(key: 'op:churn-b', leaseSeconds: 60);
        $this->service->markAttemptStarted($abandoned->attempt, $abandoned->ownerTokenOrFail());
        $this->travel(120)->seconds();
        $this->claim(key: 'op:churn-b', leaseSeconds: 60);
        $this->claim(key: 'op:churn-b', leaseSeconds: 60);

        $worst = DB::connection(self::DURABLE_CONNECTION)
            ->table('provider_operation_attempts')
            ->whereIn('firm_id', [self::FIRM_ID, self::OTHER_FIRM_ID])
            ->max('send_count');

        $this->assertLessThanOrEqual(
            1,
            (int) $worst,
            'send_count is the at-most-once guarantee expressed as a database fact and must never exceed 1.'
        );
    }

    // ------------------------------------------------------------------
    // Connection hygiene (§A11 preconditions)
    // ------------------------------------------------------------------

    public function test_the_durable_connection_is_left_with_no_open_transaction_and_no_tenant_session_setting(): void
    {
        $claim = $this->claim();
        $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail());

        $this->assertSame(0, DB::connection(self::DURABLE_CONNECTION)->transactionLevel());

        // This service never pushes app.current_firm_id, so nothing can
        // linger on the durable session for a later reuse to inherit.
        $leaked = DB::connection(self::DURABLE_CONNECTION)
            ->selectOne("select current_setting('app.current_firm_id', true) as value");

        $this->assertTrue(
            $leaked->value === null || $leaked->value === '',
            'The durable connection must not carry tenant context, but found: '.var_export($leaked->value, true)
        );
    }

    /**
     * The decisive test for this whole design, and the one Checkpoint
     * 8.1 would have failed: the durable write must complete while the
     * caller's own session holds FOR UPDATE on the real
     * `firm_integrations` row — the exact lock `PullSyncJob` takes
     * across its provider call.
     *
     * A bounded `lock_timeout` on the durable session makes a regression
     * fail loudly in seconds instead of hanging the suite, which is what
     * the rejected design actually did.
     */
    public function test_the_durable_write_completes_while_the_caller_holds_for_update_on_the_connection_row(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create()
        );

        DB::connection(self::DURABLE_CONNECTION)->statement("set lock_timeout = '5s'");

        try {
            $this->runWithFirmContext($firm, function () use ($firm, $connection) {
                // Exactly PullSyncJob's shape: FOR UPDATE on the
                // connection row, held for the duration of what would be
                // the provider call.
                $locked = FirmIntegration::query()->whereKey($connection->id)->lockForUpdate()->first();
                $this->assertNotNull($locked);

                $claim = $this->service->claim(
                    logicalOperationKey: 'plaid_pull:locked-connection:page-1',
                    providerKey: 'plaid',
                    firmId: $firm->id,
                    firmIntegrationId: $connection->id,
                    operationType: 'transactions_sync',
                );

                $this->assertSame(ProviderOperationClaimDecision::Proceed, $claim->decision);

                $started = $this->service->markAttemptStarted($claim->attempt, $claim->ownerTokenOrFail());
                $this->assertSame(1, (int) $started->send_count);
            });
        } finally {
            DB::connection(self::DURABLE_CONNECTION)->statement('set lock_timeout = default');
            DB::connection(self::DURABLE_CONNECTION)
                ->table('provider_operation_attempts')
                ->where('logical_operation_key', 'plaid_pull:locked-connection:page-1')
                ->delete();
        }
    }

    public function test_the_gate_row_carries_no_foreign_keys_so_it_never_waits_on_a_locked_connection_row(): void
    {
        $constraints = DB::connection(self::DURABLE_CONNECTION)->select(<<<'SQL'
            select conname
            from pg_constraint
            where conrelid = 'provider_operation_attempts'::regclass
              and contype = 'f'
            SQL);

        $this->assertSame(
            [],
            $constraints,
            'provider_operation_attempts must have no foreign keys — Checkpoint 8.1 proved a cross-session FK '
                .'reference to a row held FOR UPDATE deadlocks in production.'
        );
    }
}
