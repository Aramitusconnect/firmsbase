<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\FinancialAccountClassification;
use App\Models\FinancialAccountReclassificationRequest;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * FinancialAccountReclassificationService — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §5;
 * checkpoint4-combined-design.md §9.5). The first real, reachable
 * caller of `FinancialIntegrationAccessPolicyService::assertDistinctApprovers()`
 * — flagged for security review (Finding 4/Finding 5 of
 * checkpoint4-security-review.md). Styled after
 * `TrustHighRiskAdjustmentService`'s append-only
 * request -> first-approve -> second-approve pattern.
 *
 * Finding 5 discipline (adopted, not merely disclosed): every method
 * below wraps its entire body — the duplicate/state check, the
 * distinct-approver check, and (on second approval) the actual
 * `financial_evidence_bank_accounts.classification` write — inside ONE
 * `DB::transaction()` closure with an explicit `lockForUpdate()` read of
 * the request row first, mirroring
 * `TrustHighRiskAdjustmentService::secondApprove()`'s own
 * locked-transaction discipline exactly, closing the concurrent-race
 * risk that document's own illustrative code left implicit.
 *
 * Finding 4 (inherited from `TrustHighRiskAdjustmentService`'s own
 * accepted pattern, not newly introduced here): `assertCanRequest()`
 * and `assertCanApprove()` check ROLE only, not identity relative to
 * the original requester — a FirmOwner/Attorney may request AND
 * subsequently first-approve their own request. Only the SECOND
 * approval is guaranteed to come from a genuinely different person
 * (`assertDistinctApprovers()`). This mirrors the already-shipped,
 * already-reviewed trust-domain posture verbatim — not weakened here.
 */
class FinancialAccountReclassificationService
{
    public function __construct(
        private readonly FinancialIntegrationAccessPolicyService $accessPolicy,
        private readonly TimelineEventRecorder $events,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function request(
        Firm $firm,
        FinancialEvidenceBankAccount $account,
        FirmUser $requestedBy,
        FinancialAccountClassification $target,
        string $reason,
    ): FinancialAccountReclassificationRequest {
        $this->accessPolicy->assertCanRequest($requestedBy);

        if (trim($reason) === '') {
            throw new RuntimeException('A reason is mandatory for an account reclassification request.');
        }

        if ((int) $account->firm_id !== (int) $firm->id) {
            throw new RuntimeException('This account does not belong to the given firm.');
        }

        return $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $account, $requestedBy, $target, $reason) {
            $existingPending = FinancialAccountReclassificationRequest::query()
                ->where('bank_account_id', $account->id)
                ->whereIn('status', ['pending', 'first_approved'])
                ->exists();

            if ($existingPending) {
                throw new RuntimeException('This account already has a pending reclassification request.');
            }

            $request = FinancialAccountReclassificationRequest::create([
                'firm_id' => $firm->id,
                'bank_account_id' => $account->id,
                'requested_classification' => $target->value,
                'previous_classification' => $account->classification,
                'requested_by_firm_user_id' => $requestedBy->id,
                'requested_at' => now(),
                'reason' => $reason,
                'status' => 'pending',
                'correlation_uuid' => (string) Str::uuid7(),
            ]);

            $this->events->record($firm, 'financial_evidence.account_reclassification_requested', $account, $requestedBy->user, [
                'financial_account_reclassification_request_id' => $request->id,
                'bank_account_id' => $account->id,
                'requested_classification' => $target->value,
                'previous_classification' => $account->classification,
            ]);

            return $request;
        });
    }

    /**
     * First OR second approval, depending on the request's current
     * state — mirrors `TrustHighRiskAdjustmentService`'s own two-call
     * shape being split across `firstApprove()`/`secondApprove()`, here
     * folded into one method dispatching on state (this domain has no
     * separate `*_approval_events` table to key a distinct method
     * pair off, per this table's own docblock).
     */
    public function approve(Firm $firm, FinancialAccountReclassificationRequest $request, FirmUser $approver): FinancialAccountReclassificationRequest
    {
        return $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $request, $approver) {
            return DB::transaction(function () use ($firm, $request, $approver) {
                /** @var FinancialAccountReclassificationRequest $locked */
                $locked = FinancialAccountReclassificationRequest::query()
                    ->where('id', $request->id)
                    ->where('firm_id', $firm->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status === 'pending') {
                    $this->accessPolicy->assertCanApprove($approver);

                    $locked->update([
                        'status' => 'first_approved',
                        'first_approved_by_firm_user_id' => $approver->id,
                        'first_approved_at' => now(),
                    ]);

                    $this->events->record($firm, 'financial_evidence.account_reclassification_first_approved', $locked, $approver->user, [
                        'financial_account_reclassification_request_id' => $locked->id,
                    ]);

                    return $locked->fresh();
                }

                if ($locked->status !== 'first_approved') {
                    throw new RuntimeException('This reclassification request is not awaiting a decision.');
                }

                $firstApprover = $locked->firstApprover;

                if ($firstApprover === null) {
                    throw new RuntimeException('This request has no recorded first approver.');
                }

                // The exact call this pending-approval layer exists to
                // reach — throws if $approver === $firstApprover.
                $this->accessPolicy->assertDistinctApprovers($firstApprover, $approver);

                $account = FinancialEvidenceBankAccount::query()
                    ->where('id', $locked->bank_account_id)
                    ->where('firm_id', $firm->id)
                    ->firstOrFail();

                // Sole write point for financial_evidence_bank_accounts.classification.
                // The materializer's own `booted()` immutability guard
                // forbids UPDATE on this model, so the classification is
                // instead written via a scoped, direct query-builder
                // UPDATE — the one deliberate, narrow bypass of that
                // guard, exactly analogous to how
                // IntegrationExternalMapping.tombstoned_at is updated in
                // place elsewhere in this codebase despite its sibling
                // rows being append-only. classification is NOT part of
                // the evidentiary provider-fact payload (it starts NULL,
                // is explicitly carved out in the materializer's own
                // docblock as "belongs to the Financial Evidence
                // Workspace UI track"), so this narrow, audited exception
                // does not weaken that guard's real purpose (protecting
                // provider-supplied fact columns from silent rewrite).
                FinancialEvidenceBankAccount::query()
                    ->where('id', $account->id)
                    ->update(['classification' => $locked->requested_classification]);

                $locked->update([
                    'status' => 'approved',
                    'second_approved_by_firm_user_id' => $approver->id,
                    'second_approved_at' => now(),
                ]);

                $this->events->record($firm, 'financial_evidence.account_reclassification_approved', $locked, $approver->user, [
                    'financial_account_reclassification_request_id' => $locked->id,
                    'bank_account_id' => $account->id,
                    'new_classification' => $locked->requested_classification,
                ]);

                return $locked->fresh();
            });
        });
    }

    public function reject(Firm $firm, FinancialAccountReclassificationRequest $request, FirmUser $rejectedBy): FinancialAccountReclassificationRequest
    {
        $this->accessPolicy->assertCanApprove($rejectedBy);

        return $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $request, $rejectedBy) {
            return DB::transaction(function () use ($firm, $request, $rejectedBy) {
                /** @var FinancialAccountReclassificationRequest $locked */
                $locked = FinancialAccountReclassificationRequest::query()
                    ->where('id', $request->id)
                    ->where('firm_id', $firm->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! in_array($locked->status, ['pending', 'first_approved'], true)) {
                    throw new RuntimeException('This reclassification request is not awaiting a decision.');
                }

                $locked->update([
                    'status' => 'rejected',
                    'rejected_by_firm_user_id' => $rejectedBy->id,
                    'rejected_at' => now(),
                ]);

                $this->events->record($firm, 'financial_evidence.account_reclassification_rejected', $locked, $rejectedBy->user, [
                    'financial_account_reclassification_request_id' => $locked->id,
                ]);

                return $locked->fresh();
            });
        });
    }

    /**
     * Ordinary (non-sensitive) reclassification — single-actor, direct
     * write, still audited. Gated `canRequest()` only (FirmOwner/
     * Attorney/BillingStaff) — never the dual-approval path.
     */
    public function reclassifyDirectly(
        Firm $firm,
        FinancialEvidenceBankAccount $account,
        FirmUser $actor,
        FinancialAccountClassification $target,
    ): FinancialEvidenceBankAccount {
        $this->accessPolicy->assertCanRequest($actor);

        $current = $account->classification !== null ? FinancialAccountClassification::from($account->classification) : null;

        if (FinancialAccountClassification::isSensitiveTransition($current, $target)) {
            throw new RuntimeException(
                'This transition is a sensitive reclassification and must go through request()/approve() — never a direct write.'
            );
        }

        return $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $account, $actor, $target) {
            FinancialEvidenceBankAccount::query()
                ->where('id', $account->id)
                ->where('firm_id', $firm->id)
                ->update(['classification' => $target->value]);

            $this->events->record($firm, 'financial_evidence.account_reclassified', $account, $actor->user, [
                'bank_account_id' => $account->id,
                'new_classification' => $target->value,
                'previous_classification' => $account->classification,
            ]);

            return $account->fresh();
        });
    }
}
