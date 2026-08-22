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
    ) {}

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

        (new TenantContextService)->runWithFirmContext($request->firm_id, fn () => $request->update(['status' => KeyDestructionRequestStatus::PendingApproval]));

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

    /**
     * $request is accepted explicitly rather than lazy-loaded off
     * $approval->keyDestructionRequest — key_destruction_approvals
     * carries no firm_id column of its own, so once
     * key_destruction_requests is FORCE RLS (this batch), that lazy
     * load would silently return null under no ambient context (not an
     * error), and null->update(...) would throw a fatal Error on every
     * successful second-approval. Every existing caller already has the
     * parent request in scope at the call site. The mismatch check
     * below guards against a caller pairing the wrong request with the
     * wrong approval — the application-layer analogue of a
     * composite-FK check, since key_destruction_approvals has no
     * firm_id to key a DB-level constraint on.
     */
    public function secondApprove(KeyDestructionApproval $approval, KeyDestructionRequest $request, PlatformAdmin $approver): KeyDestructionApproval
    {
        if ($request->id !== $approval->key_destruction_request_id) {
            throw new \InvalidArgumentException('The given KeyDestructionRequest does not match this KeyDestructionApproval.');
        }

        $decision = $this->highRiskPolicy->secondApprove($approval->highRiskChangeRequest, $approver);

        $approval->update([
            'status' => $decision->status,
            'second_approved_by' => $approver->id,
            'second_approved_at' => now(),
        ]);

        if ($decision->status === HighRiskChangeRequestStatus::Approved) {
            (new TenantContextService)->runWithFirmContext($request->firm_id, fn () => $request->update(['status' => KeyDestructionRequestStatus::Approved]));
        }

        return $approval->fresh();
    }

    /**
     * See secondApprove()'s docblock for why $request is now an
     * explicit parameter instead of a lazy $approval->keyDestructionRequest
     * load, and why the mismatch check exists.
     */
    public function deny(KeyDestructionApproval $approval, KeyDestructionRequest $request, PlatformAdmin $denier, string $reason): KeyDestructionApproval
    {
        if ($request->id !== $approval->key_destruction_request_id) {
            throw new \InvalidArgumentException('The given KeyDestructionRequest does not match this KeyDestructionApproval.');
        }

        $decision = $this->highRiskPolicy->deny($approval->highRiskChangeRequest, $denier, $reason);

        $approval->update([
            'status' => $decision->status,
            'denied_by' => $denier->id,
            'denied_at' => now(),
            'denial_reason' => $reason,
        ]);

        (new TenantContextService)->runWithFirmContext($request->firm_id, fn () => $request->update(['status' => KeyDestructionRequestStatus::Denied]));

        return $approval->fresh();
    }

    public function isApproved(KeyDestructionRequest $request): bool
    {
        return $request->approval !== null && $request->approval->status === HighRiskChangeRequestStatus::Approved;
    }
}
