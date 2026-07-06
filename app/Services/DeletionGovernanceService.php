<?php

namespace App\Services;

use App\Enums\DeletionRequestStatus;
use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\LegalHoldScope;
use App\Enums\OffboardingExportStatus;
use App\Models\DeletionRequest;
use App\ValueObjects\DeletionClearanceResult;

/**
 * DeletionGovernanceService — the clearance gate AND finalization step
 * for deletion_requests. Approved decision #1: finalize() transitions a
 * fully-approved request to ReadyForExecution and stops there — this
 * service (and Phase 17 generally) NEVER physically deletes the target
 * business row. Physical execution is deliberately out of scope for
 * this phase; it belongs to a later, separately-scoped, explicitly
 * approved action.
 */
class DeletionGovernanceService
{
    public function __construct(
        private readonly RetentionPolicyService $retentionPolicyService,
        private readonly LegalHoldService $legalHoldService,
    ) {
    }

    public function checkClearance(DeletionRequest $request, \App\Enums\RetentionRecordType $recordType): DeletionClearanceResult
    {
        $exportCleared = $request->offboarding_export_id !== null
            && $request->offboardingExport?->status === OffboardingExportStatus::Verified;

        if (! $exportCleared) {
            return new DeletionClearanceResult(false, false, false, 'A verified offboarding export is required before this deletion can be requested.');
        }

        $firm = $request->firm;
        $policy = $this->retentionPolicyService->resolveEffectivePolicyFor($firm, $recordType);
        $retentionCleared = $this->retentionPolicyService
            ->isRetentionCleared($policy, $request->created_at ?? now())
            ->cleared;

        if (! $retentionCleared) {
            return new DeletionClearanceResult(true, false, false, 'Retention policy has not cleared for this record.');
        }

        $legalHoldCleared = ! $this->legalHoldService->hasActiveHold($firm, LegalHoldScope::Matter, $request->subject_id)
            && ! $this->legalHoldService->hasActiveHold($firm, LegalHoldScope::Firm);

        if (! $legalHoldCleared) {
            return new DeletionClearanceResult(true, true, false, 'An active legal hold blocks this deletion.');
        }

        return new DeletionClearanceResult(true, true, true);
    }

    public function submitForApproval(DeletionRequest $request, \App\Enums\RetentionRecordType $recordType): DeletionRequest
    {
        $clearance = $this->checkClearance($request, $recordType);

        if (! $clearance->isClear()) {
            $status = match (true) {
                ! $clearance->exportCleared => DeletionRequestStatus::ExportClearancePending,
                ! $clearance->retentionCleared => DeletionRequestStatus::RetentionClearancePending,
                default => DeletionRequestStatus::LegalHoldBlocked,
            };

            $request->update(['status' => $status]);

            throw new \RuntimeException($clearance->reason ?? 'Deletion request is not yet clear for approval.');
        }

        $request->update(['status' => DeletionRequestStatus::PendingApproval]);

        return $request->fresh();
    }

    /**
     * Called only after DeletionApprovalService confirms the linked
     * approval reached HighRiskChangeRequestStatus::Approved. Terminal
     * state — no execution follows in Phase 17.
     */
    public function finalize(DeletionRequest $request): DeletionRequest
    {
        if ($request->approval === null || $request->approval->status !== HighRiskChangeRequestStatus::Approved) {
            throw new \RuntimeException('Deletion request cannot be finalized without an Approved deletion_approvals row.');
        }

        $request->update(['status' => DeletionRequestStatus::ReadyForExecution]);

        return $request->fresh();
    }
}
