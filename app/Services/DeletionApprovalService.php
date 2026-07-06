<?php

namespace App\Services;

use App\Enums\DeletionRequestStatus;
use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Models\DeletionApproval;
use App\Models\DeletionRequest;
use App\Models\PlatformAdmin;

/**
 * DeletionApprovalService — mirrors KeyDestructionApprovalService, but
 * uses the EXISTING HighRiskChangeType::ProductionDataDeletion case
 * (approved decision #2 — reused for deletion governance only, never
 * for key destruction). On a fully-approved second approval, transitions
 * the linked DeletionRequest straight to ReadyForExecution — never
 * further; Phase 17 performs no physical row delete.
 */
class DeletionApprovalService
{
    public function __construct(
        private readonly HighRiskPlatformChangePolicyService $highRiskPolicy,
    ) {
    }

    public function requestApproval(DeletionRequest $request, PlatformAdmin $requestedBy, string $reason): DeletionApproval
    {
        $highRiskRequest = $this->highRiskPolicy->request(
            HighRiskChangeType::ProductionDataDeletion,
            $requestedBy,
            $reason,
            ['firm_id' => $request->firm_id, 'deletion_request_id' => $request->id],
        );

        $approval = DeletionApproval::create([
            'deletion_request_id' => $request->id,
            'high_risk_change_request_id' => $highRiskRequest->id,
            'status' => HighRiskChangeRequestStatus::Pending,
        ]);

        $request->update(['status' => \App\Enums\DeletionRequestStatus::PendingApproval]);

        return $approval;
    }

    public function firstApprove(DeletionApproval $approval, PlatformAdmin $approver): DeletionApproval
    {
        $decision = $this->highRiskPolicy->firstApprove($approval->highRiskChangeRequest, $approver);

        $approval->update([
            'status' => $decision->status,
            'first_approved_by' => $approver->id,
            'first_approved_at' => now(),
        ]);

        return $approval->fresh();
    }

    public function secondApprove(DeletionApproval $approval, PlatformAdmin $approver): DeletionApproval
    {
        $decision = $this->highRiskPolicy->secondApprove($approval->highRiskChangeRequest, $approver);

        $approval->update([
            'status' => $decision->status,
            'second_approved_by' => $approver->id,
            'second_approved_at' => now(),
        ]);

        if ($decision->status === HighRiskChangeRequestStatus::Approved) {
            $approval->deletionRequest->update(['status' => DeletionRequestStatus::ReadyForExecution]);
        }

        return $approval->fresh();
    }

    public function deny(DeletionApproval $approval, PlatformAdmin $denier, string $reason): DeletionApproval
    {
        $decision = $this->highRiskPolicy->deny($approval->highRiskChangeRequest, $denier, $reason);

        $approval->update([
            'status' => $decision->status,
            'denied_by' => $denier->id,
            'denied_at' => now(),
            'denial_reason' => $reason,
        ]);

        $approval->deletionRequest->update(['status' => DeletionRequestStatus::Denied]);

        return $approval->fresh();
    }

    public function isApproved(DeletionRequest $request): bool
    {
        return $request->approval !== null && $request->approval->status === HighRiskChangeRequestStatus::Approved;
    }
}
