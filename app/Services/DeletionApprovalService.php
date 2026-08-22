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
    ) {}

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

        (new TenantContextService)->runWithFirmContext($request->firm_id, fn () => $request->update(['status' => DeletionRequestStatus::PendingApproval]));

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

    /**
     * $request is accepted explicitly rather than lazy-loaded off
     * $approval->deletionRequest — deletion_approvals carries no
     * firm_id column of its own, so once deletion_requests is FORCE
     * RLS (this batch), that lazy load would silently return null
     * under no ambient context (not an error), and null->update(...)
     * would throw a fatal Error on every successful second-approval.
     * Every existing caller already has the parent request in scope at
     * the call site. The mismatch check below guards against a caller
     * pairing the wrong request with the wrong approval — the
     * application-layer analogue of a composite-FK check, since
     * deletion_approvals has no firm_id to key a DB-level constraint
     * on.
     */
    public function secondApprove(DeletionApproval $approval, DeletionRequest $request, PlatformAdmin $approver): DeletionApproval
    {
        if ($request->id !== $approval->deletion_request_id) {
            throw new \InvalidArgumentException('The given DeletionRequest does not match this DeletionApproval.');
        }

        $decision = $this->highRiskPolicy->secondApprove($approval->highRiskChangeRequest, $approver);

        $approval->update([
            'status' => $decision->status,
            'second_approved_by' => $approver->id,
            'second_approved_at' => now(),
        ]);

        if ($decision->status === HighRiskChangeRequestStatus::Approved) {
            (new TenantContextService)->runWithFirmContext($request->firm_id, fn () => $request->update(['status' => DeletionRequestStatus::ReadyForExecution]));
        }

        return $approval->fresh();
    }

    /**
     * See secondApprove()'s docblock for why $request is now an
     * explicit parameter instead of a lazy $approval->deletionRequest
     * load, and why the mismatch check exists.
     */
    public function deny(DeletionApproval $approval, DeletionRequest $request, PlatformAdmin $denier, string $reason): DeletionApproval
    {
        if ($request->id !== $approval->deletion_request_id) {
            throw new \InvalidArgumentException('The given DeletionRequest does not match this DeletionApproval.');
        }

        $decision = $this->highRiskPolicy->deny($approval->highRiskChangeRequest, $denier, $reason);

        $approval->update([
            'status' => $decision->status,
            'denied_by' => $denier->id,
            'denied_at' => now(),
            'denial_reason' => $reason,
        ]);

        (new TenantContextService)->runWithFirmContext($request->firm_id, fn () => $request->update(['status' => DeletionRequestStatus::Denied]));

        return $approval->fresh();
    }

    public function isApproved(DeletionRequest $request): bool
    {
        return $request->approval !== null && $request->approval->status === HighRiskChangeRequestStatus::Approved;
    }
}
