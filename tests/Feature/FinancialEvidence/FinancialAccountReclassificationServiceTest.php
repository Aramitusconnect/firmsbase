<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialEvidence;

use App\Enums\FirmUserRole;
use App\Integrations\Enums\FinancialAccountClassification;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\FinancialAccountReclassificationService;
use App\Models\FinancialAccountReclassificationRequest;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TimelineEvent;
use App\Models\TrustLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * FinancialAccountReclassificationServiceTest — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on"). This
 * is the highest-scrutiny target in this whole checkpoint per
 * checkpoint4-security-review.md's Finding 4/Finding 5: it is the
 * first real, reachable caller of
 * FinancialIntegrationAccessPolicyService::assertDistinctApprovers(),
 * and the concrete, live implementation must be traced end to end
 * rather than trusted from the design doc alone.
 *
 * These tests prove, against the REAL service and REAL database
 * transaction, not a mock:
 *   (a) a single actor cannot complete both required approvals — no
 *       self-approval path exists via approve(), even attempting it
 *       through the one method the Filament UI actually calls;
 *   (b) financial_evidence_bank_accounts.classification is NOT
 *       mutated by the first approval alone — it changes only at the
 *       exact moment the SECOND, distinct approver's approve() call
 *       succeeds;
 *   (c) the pending/first_approved state is visible and correct in
 *       between;
 *   (d) no reclassification action of any kind ever inserts a
 *       trust_ledger_entries row.
 *
 * Confirmed by direct source read (not merely inferred from the
 * design doc) that the live FinancialAccountReclassificationService
 * wraps every state transition in DB::transaction() with an explicit
 * lockForUpdate() read of the request row first — the concurrency
 * discipline checkpoint4-security-review.md's Finding 5 asked for is
 * present in the actual shipped code.
 */
class FinancialAccountReclassificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialAccountReclassificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FinancialAccountReclassificationService::class);
    }

    // ------------------------------------------------------------
    // (a) No self-approval path — a single actor cannot complete
    //     both approvals.
    // ------------------------------------------------------------

    public function test_the_same_actor_cannot_provide_both_the_first_and_second_approval(): void
    {
        // Durable Firm required: the same-actor branch calls
        // FinancialIntegrationAccessPolicyService::assertDistinctApprovers(),
        // whose violation path writes
        // integration_governance.distinct_approver_violation via
        // TimelineEventRecorder::recordOnIndependentConnection() (the
        // separate 'pgsql_audit' connection) — which cannot see a Firm
        // still uncommitted inside this test's own RefreshDatabase
        // transaction. Mirrors the established pattern in
        // tests/Feature/Integrations/IntegrationAuditEventTypeTest.php's
        // own test_distinct_approver_violation_and_confirmed_companion_fire_on_their_respective_paths().
        [$firm, $account, $requester] = $this->makeDurableFirmAccountAndUser();

        $request = $this->runWithFirmContext($firm, fn () => $this->service->request(
            $firm, $account, $requester, FinancialAccountClassification::TrustIolta, 'Client trust funds now route through this account.'
        ));

        // First approval, by a distinct approver.
        $approver = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]));

        $afterFirst = $this->runWithFirmContext($firm, fn () => $this->service->approve($firm, $request, $approver));
        $this->assertSame('first_approved', $afterFirst->status);
        $this->assertSame($approver->id, $afterFirst->first_approved_by_firm_user_id);

        // The SAME approver attempts the second approval on their own
        // first approval — this must be rejected, not silently
        // accepted, and must not be reachable via any code path other
        // than the same public approve() method the UI calls.
        $threw = false;

        try {
            $this->runWithFirmContext($firm, function () use ($firm, $afterFirst, $approver) {
                $locked = FinancialAccountReclassificationRequest::query()->where('id', $afterFirst->id)->firstOrFail();

                $this->service->approve($firm, $locked, $approver);
            });
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertSame('The second approver must be a different firm user than the first approver.', $e->getMessage());
        }

        $this->assertTrue($threw, 'Expected the self-approval attempt to throw.');
    }

    public function test_self_approval_attempt_leaves_the_request_in_first_approved_state_not_approved(): void
    {
        // Durable Firm required — see the previous test's docblock.
        [$firm, $account, $requester] = $this->makeDurableFirmAccountAndUser();

        $request = $this->runWithFirmContext($firm, fn () => $this->service->request(
            $firm, $account, $requester, FinancialAccountClassification::TrustIolta, 'Reclassifying to trust.'
        ));

        $approver = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]));
        $afterFirst = $this->runWithFirmContext($firm, fn () => $this->service->approve($firm, $request, $approver));

        try {
            $this->runWithFirmContext($firm, function () use ($firm, $afterFirst, $approver) {
                $locked = FinancialAccountReclassificationRequest::query()->where('id', $afterFirst->id)->firstOrFail();

                $this->service->approve($firm, $locked, $approver);
            });
            $this->fail('Expected the self-approval attempt to throw.');
        } catch (RuntimeException $e) {
            // expected
        }

        $reloaded = $this->runWithFirmContext($firm, fn () => FinancialAccountReclassificationRequest::query()->find($request->id));
        $this->assertSame('first_approved', $reloaded->status, 'A rejected self-approval attempt must not advance the request past first_approved.');
        $this->assertNull($reloaded->second_approved_by_firm_user_id);
        $this->assertNull($reloaded->second_approved_at);
    }

    public function test_a_request_cannot_be_created_by_one_role_tier_and_first_approved_by_an_unauthorized_role(): void
    {
        // Durable Firm required: approve()'s assertCanApprove() denial
        // (BillingStaff is below the approve ceiling) routes through
        // FinancialIntegrationAccessPolicyService::recordDenied(), which
        // ALSO uses the independent 'pgsql_audit' connection — same
        // shape as makeDurableFirmAccountAndUser()'s own docblock
        // describes for assertDistinctApprovers()'s violation path.
        [$firm, $account, $requester] = $this->makeDurableFirmAccountAndUser();

        $request = $this->runWithFirmContext($firm, fn () => $this->service->request(
            $firm, $account, $requester, FinancialAccountClassification::TrustIolta, 'Reason.'
        ));

        // BillingStaff may REQUEST but never APPROVE (assertCanApprove()
        // excludes BillingStaff) — confirm the real service enforces
        // this on the first-approval branch too, not merely on
        // request().
        $billingStaff = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::BillingStaff)->create(['firm_id' => $firm->id]));

        $this->expectException(RuntimeException::class);

        $this->runWithFirmContext($firm, fn () => $this->service->approve($firm, $request, $billingStaff));
    }

    // ------------------------------------------------------------
    // (b) The classification mutation genuinely does not happen
    //     until the SECOND, distinct approval.
    // ------------------------------------------------------------

    public function test_the_account_classification_is_unchanged_after_only_the_first_approval(): void
    {
        [$firm, $account, $requester] = $this->makeFirmAccountAndUser();
        $this->assertNull($account->classification);

        $request = $this->runWithFirmContext($firm, fn () => $this->service->request(
            $firm, $account, $requester, FinancialAccountClassification::TrustIolta, 'Reason.'
        ));

        $firstApprover = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]));
        $this->runWithFirmContext($firm, fn () => $this->service->approve($firm, $request, $firstApprover));

        $reloadedAccount = $this->runWithFirmContext($firm, fn () => FinancialEvidenceBankAccount::query()->find($account->id));
        $this->assertNull(
            $reloadedAccount->classification,
            'The classification column must NOT be written until the SECOND, distinct approval — a single approval is not sufficient to mutate the account.'
        );
    }

    public function test_the_account_classification_is_mutated_only_after_a_second_distinct_approver_acts(): void
    {
        [$firm, $account, $requester] = $this->makeFirmAccountAndUser();

        $request = $this->runWithFirmContext($firm, fn () => $this->service->request(
            $firm, $account, $requester, FinancialAccountClassification::TrustIolta, 'Reason.'
        ));

        $firstApprover = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]));
        $secondApprover = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]));

        $this->runWithFirmContext($firm, fn () => $this->service->approve($firm, $request, $firstApprover));

        // Still unmutated immediately before the second approval.
        $stillUnclassified = $this->runWithFirmContext($firm, fn () => FinancialEvidenceBankAccount::query()->find($account->id));
        $this->assertNull($stillUnclassified->classification);

        $finalRequest = $this->runWithFirmContext($firm, fn () => $this->service->approve($firm, $request->fresh(), $secondApprover));

        $this->assertSame('approved', $finalRequest->status);
        $this->assertSame($secondApprover->id, $finalRequest->second_approved_by_firm_user_id);

        $mutatedAccount = $this->runWithFirmContext($firm, fn () => FinancialEvidenceBankAccount::query()->find($account->id));
        $this->assertSame(FinancialAccountClassification::TrustIolta->value, $mutatedAccount->classification);
    }

    // ------------------------------------------------------------
    // (c) Pending-approval state is visible/correct in between.
    // ------------------------------------------------------------

    public function test_request_creates_a_pending_row_with_the_correct_previous_and_requested_classification(): void
    {
        [$firm, $account, $requester] = $this->makeFirmAccountAndUser();

        $request = $this->runWithFirmContext($firm, fn () => $this->service->request(
            $firm, $account, $requester, FinancialAccountClassification::TrustIolta, 'Reason for the record.'
        ));

        $this->assertSame('pending', $request->status);
        $this->assertSame('trust_iolta', $request->requested_classification);
        $this->assertNull($request->previous_classification);
        $this->assertSame($requester->id, $request->requested_by_firm_user_id);
        $this->assertNotNull($request->correlation_uuid);
        $this->assertNull($request->first_approved_by_firm_user_id);
        $this->assertNull($request->second_approved_by_firm_user_id);
    }

    public function test_state_machine_visibly_transitions_pending_to_first_approved_to_approved(): void
    {
        [$firm, $account, $requester] = $this->makeFirmAccountAndUser();

        $request = $this->runWithFirmContext($firm, fn () => $this->service->request(
            $firm, $account, $requester, FinancialAccountClassification::TrustIolta, 'Reason.'
        ));
        $this->assertSame('pending', $request->status);

        $first = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]));
        $second = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]));

        $this->runWithFirmContext($firm, fn () => $this->service->approve($firm, $request, $first));
        $this->assertSame('first_approved', $this->runWithFirmContext($firm, fn () => FinancialAccountReclassificationRequest::query()->find($request->id))->status);

        $this->runWithFirmContext($firm, fn () => $this->service->approve($firm, $request->fresh(), $second));
        $this->assertSame('approved', $this->runWithFirmContext($firm, fn () => FinancialAccountReclassificationRequest::query()->find($request->id))->status);
    }

    public function test_a_second_pending_request_for_the_same_account_is_rejected_while_one_is_already_in_flight(): void
    {
        [$firm, $account, $requester] = $this->makeFirmAccountAndUser();

        $this->runWithFirmContext($firm, fn () => $this->service->request(
            $firm, $account, $requester, FinancialAccountClassification::TrustIolta, 'First request.'
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This account already has a pending reclassification request.');

        $this->runWithFirmContext($firm, fn () => $this->service->request(
            $firm, $account, $requester, FinancialAccountClassification::SettlementDestination, 'Second, conflicting request.'
        ));
    }

    public function test_request_requires_a_non_empty_reason(): void
    {
        [$firm, $account, $requester] = $this->makeFirmAccountAndUser();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A reason is mandatory for an account reclassification request.');

        $this->runWithFirmContext($firm, fn () => $this->service->request(
            $firm, $account, $requester, FinancialAccountClassification::TrustIolta, '   '
        ));
    }

    public function test_reject_marks_the_request_rejected_and_never_mutates_the_account(): void
    {
        [$firm, $account, $requester] = $this->makeFirmAccountAndUser();

        $request = $this->runWithFirmContext($firm, fn () => $this->service->request(
            $firm, $account, $requester, FinancialAccountClassification::TrustIolta, 'Reason.'
        ));

        $rejector = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]));
        $rejected = $this->runWithFirmContext($firm, fn () => $this->service->reject($firm, $request, $rejector));

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame($rejector->id, $rejected->rejected_by_firm_user_id);

        $account = $this->runWithFirmContext($firm, fn () => FinancialEvidenceBankAccount::query()->find($account->id));
        $this->assertNull($account->classification);
    }

    public function test_a_rejected_or_approved_request_cannot_be_approved_again(): void
    {
        [$firm, $account, $requester] = $this->makeFirmAccountAndUser();

        $request = $this->runWithFirmContext($firm, fn () => $this->service->request(
            $firm, $account, $requester, FinancialAccountClassification::TrustIolta, 'Reason.'
        ));

        $rejector = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]));
        $this->runWithFirmContext($firm, fn () => $this->service->reject($firm, $request, $rejector));

        $anotherApprover = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This reclassification request is not awaiting a decision.');

        $this->runWithFirmContext($firm, fn () => $this->service->approve($firm, $request->fresh(), $anotherApprover));
    }

    // ------------------------------------------------------------
    // (d) No reclassification action ever mutates the trust ledger.
    // ------------------------------------------------------------

    public function test_no_reclassification_action_ever_inserts_a_trust_ledger_entry(): void
    {
        [$firm, $account, $requester] = $this->makeFirmAccountAndUser();

        $before = TrustLedgerEntry::query()->count();

        $request = $this->runWithFirmContext($firm, fn () => $this->service->request(
            $firm, $account, $requester, FinancialAccountClassification::TrustIolta, 'Reason.'
        ));

        $first = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]));
        $second = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]));

        $this->runWithFirmContext($firm, fn () => $this->service->approve($firm, $request, $first));
        $this->runWithFirmContext($firm, fn () => $this->service->approve($firm, $request->fresh(), $second));

        $after = TrustLedgerEntry::query()->count();

        $this->assertSame($before, $after, 'A financial-account reclassification must never write a trust_ledger_entries row, approved or not.');
    }

    public function test_reclassify_directly_never_touches_the_trust_ledger_either(): void
    {
        [$firm, $account, $actor] = $this->makeFirmAccountAndUser();

        $before = TrustLedgerEntry::query()->count();

        $this->runWithFirmContext($firm, fn () => $this->service->reclassifyDirectly(
            $firm, $account, $actor, FinancialAccountClassification::Investment
        ));

        $this->assertSame($before, TrustLedgerEntry::query()->count());
    }

    // ------------------------------------------------------------
    // Ordinary vs. sensitive transitions
    // ------------------------------------------------------------

    public function test_reclassify_directly_writes_immediately_for_a_non_sensitive_transition(): void
    {
        [$firm, $account, $actor] = $this->makeFirmAccountAndUser();

        $updated = $this->runWithFirmContext($firm, fn () => $this->service->reclassifyDirectly(
            $firm, $account, $actor, FinancialAccountClassification::Investment
        ));

        $this->assertSame('investment', $updated->classification);
    }

    public function test_reclassify_directly_refuses_a_sensitive_transition_and_leaves_the_account_untouched(): void
    {
        [$firm, $account, $actor] = $this->makeFirmAccountAndUser();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This transition is a sensitive reclassification and must go through request()/approve() — never a direct write.');

        $this->runWithFirmContext($firm, fn () => $this->service->reclassifyDirectly(
            $firm, $account, $actor, FinancialAccountClassification::TrustIolta
        ));
    }

    public function test_a_trust_to_operating_transition_is_also_classified_sensitive_not_only_operating_to_trust(): void
    {
        $this->assertTrue(FinancialAccountClassification::isSensitiveTransition(
            FinancialAccountClassification::TrustIolta,
            FinancialAccountClassification::Operating,
        ));
    }

    public function test_a_settlement_destination_change_is_classified_sensitive(): void
    {
        $this->assertTrue(FinancialAccountClassification::isSensitiveTransition(
            FinancialAccountClassification::SettlementDestination,
            FinancialAccountClassification::Other,
        ));
    }

    public function test_investment_to_credit_liability_is_not_classified_sensitive(): void
    {
        $this->assertFalse(FinancialAccountClassification::isSensitiveTransition(
            FinancialAccountClassification::Investment,
            FinancialAccountClassification::CreditLiability,
        ));
    }

    // ------------------------------------------------------------
    // Cross-firm isolation of the reclassification flow itself
    // ------------------------------------------------------------

    public function test_a_request_cannot_be_created_against_an_account_belonging_to_a_different_firm(): void
    {
        [$firmA, , $requesterA] = $this->makeFirmAccountAndUser();
        [, $accountB] = $this->makeFirmAccountAndUser();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This account does not belong to the given firm.');

        $this->runWithFirmContext($firmA, fn () => $this->service->request(
            $firmA, $accountB, $requesterA, FinancialAccountClassification::TrustIolta, 'Cross-firm attempt.'
        ));
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array{0: Firm, 1: FinancialEvidenceBankAccount, 2: FirmUser}
     */
    private function makeFirmAccountAndUser(): array
    {
        $firm = Firm::factory()->create();

        return $this->runWithFirmContext($firm, function () use ($firm) {
            $connection = FirmIntegration::factory()->forFirm($firm)->create();

            $account = FinancialEvidenceBankAccount::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_account_id' => 'acc_'.Str::random(12),
                'raw_json' => [],
            ]);

            $requester = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);

            return [$firm, $account, $requester];
        });
    }

    /**
     * Same shape as makeFirmAccountAndUser(), except the Firm is
     * created via Firm::factory()->connection('pgsql_audit')->create()
     * — a real, immediate commit visible to the separate 'pgsql_audit'
     * session TimelineEventRecorder::recordOnIndependentConnection()
     * uses for assertDistinctApprovers()'s violation-path audit write.
     * Required by the two self-approval tests above (assertDistinctApprovers()'s
     * violation path) and by
     * test_a_request_cannot_be_created_by_one_role_tier_and_first_approved_by_an_unauthorized_role()
     * (assertCanApprove()'s denial path, same recordDenied() sink) — any
     * other test in this file that never reaches a denial/violation
     * branch of FinancialIntegrationAccessPolicyService can keep using
     * the plain, non-durable makeFirmAccountAndUser() above.
     *
     * @return array{0: Firm, 1: FinancialEvidenceBankAccount, 2: FirmUser}
     */
    private function makeDurableFirmAccountAndUser(): array
    {
        $firm = Firm::factory()->connection('pgsql_audit')->create();
        $this->cleanUpDurableFirmAuditTrailAfterRollback($firm);

        return $this->runWithFirmContext($firm, function () use ($firm) {
            $connection = FirmIntegration::factory()->forFirm($firm)->create();

            $account = FinancialEvidenceBankAccount::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_account_id' => 'acc_'.Str::random(12),
                'raw_json' => [],
            ]);

            $requester = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);

            return [$firm, $account, $requester];
        });
    }

    /**
     * Mirrors
     * IntegrationAuditEventTypeTest::cleanUpDurableFirmAuditTrailAfterRollback()
     * exactly (see that method's own docblock for the full deadlock
     * reasoning) — registered via beforeApplicationDestroyed() so it
     * runs after RefreshDatabase's own rollback has already released
     * the FOR KEY SHARE lock the default-connection fixtures hold on
     * this Firm row.
     */
    private function cleanUpDurableFirmAuditTrailAfterRollback(Firm $firm): void
    {
        $this->beforeApplicationDestroyed(function () use ($firm) {
            $connection = DB::connection('pgsql_audit');

            $connection->transaction(function () use ($connection, $firm) {
                $connection->statement('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, true]);
                TimelineEvent::on('pgsql_audit')->where('firm_id', $firm->id)->delete();
            });

            Firm::on('pgsql_audit')->where('id', $firm->id)->delete();
        });
    }
}
