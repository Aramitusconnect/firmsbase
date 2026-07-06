<?php

namespace App\Services;

use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Enums\KeyDestructionRequestStatus;
use App\Models\KeyDestructionApproval;
use App\Models\KeyDestructionRequest;
use App\Models\PlatformAdmin;

/**
 * KeyDestructionApprovalService — mirrors DedicatedCustomerTypeApprovalService
 * / TrustIoltaDisableAcknowledgmentService (Phase 16) EXACTLY: the ONLY
 * Phase 17 service that talks to the EXISTING, unmodified
 * HighRiskPlatformChangePolicyService for key destruction. Uses the NEW
 * HighRiskChangeType::CryptographicKeyDestruction case (approved
 * decision #2 — never ProductionDataDeletion for this).
 */
class KeyDestructionApprovalService
{
    public function __construct(
        private readonly HighRiskPlatformChangePolicyService $highRiskPolicy,
    ) {
    }

    public function requestApproval(KeyDestructionRequest $request, PlatformAdmin $requestedBy, string $reason): KeyDestructionApproval
    {
        $highRiskRequest = $this->highRiskPolicy->request(
            HighRiskChangeType::CryptographicKeyDestruction,
            $requestedBy,
            $reason,
            ['firm_id' => $request->firm_id, 'key_destruction_request_id' => $request->id],
        );

        $approval = KeyDestructionApproval::create([
            'key_destruction_request_id' => $request->id,
            'high_risk_change_request_id' => $highRiskRequest->id,
            'status' => HighRiskChangeRequestStatus::Pending,
        ]);

        $request->update(['status' => KeyDestructionRequestStatus::PendingApproval]);

        return $approval;
    }

    public function firstApprove(KeyDestructionApproval $approval, PlatformAdmin $approver): KeyDestructionApproval
    {
        $decision = $this->highRiskPolicy->firstApprove($approval->highRiskChangeRequest, $approver);

        $approval->update([
            'status' => $decision->status,
            'first_approved_by' => $approver->id,
            'first_approved_at' => now(),
        ]);

        return $approval->fresh();
    }

    public function secondApprove(KeyDestructionApproval $approval, PlatformAdmin $approver): KeyDestructionApproval
    {
        $decision = $this->highRiskPolicy->secondApprove($approval->highRiskChangeRequest, $approver);

        $approval->update([
            'status' => $decision->status,
            'second_approved_by' => $approver->id,
            'second_approved_at' => now(),
        ]);

        if ($decision->status === HighRiskChangeRequestStatus::Approved) {
            $approval->keyDestructionRequest->update(['status' => KeyDestructionRequestStatus::Approved]);
        }

        return $approval->fresh();
    }

    public function deny(KeyDestructionApproval $approval, PlatformAdmin $denier, string $reason): KeyDestructionApproval
    {
        $decision = $this->highRiskPolicy->deny($approval->highRiskChangeRequest, $denier, $reason);

        $approval->update([
            'status' => $decision->status,
            'denied_by' => $denier->id,
            'denied_at' => now(),
            'denial_reason' => $reason,
        ]);

        $approval->keyDestructionRequest->update(['status' => KeyDestructionRequestStatus::Denied]);

        return $approval->fresh();
    }

    public function isApproved(KeyDestructionRequest $request): bool
    {
        return $request->approval !== null && $request->approval->status === HighRiskChangeRequestStatus::Approved;
    }
}
