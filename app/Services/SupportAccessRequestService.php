<?php

namespace App\Services;

use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\HighRiskChangeRequest;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;

/**
 * SupportAccessRequestService — the only writer of
 * support_access_requests. reason is always required (project rule:
 * "reason required"). Standard access additionally requires firm
 * approval before a session can start (enforced by
 * SupportAccessPolicyService, not here); emergency access requires
 * emergency_justification and bypasses the firm-approval step, but is
 * never exempt from the reason requirement.
 *
 * Section 39C: emergency access is no longer self-declared-only. Every
 * SupportAccessType::Emergency request also raises a
 * high_risk_change_requests row through the EXISTING, unmodified
 * HighRiskPlatformChangePolicyService, using the change type that
 * already existed for exactly this purpose
 * (HighRiskChangeType::EmergencySupportAccess — already declared as
 * the one change type that does not require a second approver, since
 * HighRiskChangeRequest::requiresSecondApproval() is false for it).
 * The link back to this SupportAccessRequest is stored in the
 * high_risk_change_requests row's existing, already-fillable
 * `metadata` json column (no schema change needed), mirroring exactly
 * how TrustModeActivationService (Phase 13) and
 * TrustIoltaDisableAcknowledgmentService link their own requests via
 * metadata rather than a new column. isEmergencyHighRiskApproved()
 * mirrors TrustIoltaDisableAcknowledgmentService::isAdminApproved()'s
 * exact query shape. SupportAccessPolicyService::canStartSession() is
 * the single place that actually enforces this — this service only
 * raises the high-risk request, it never gates a session itself.
 */
class SupportAccessRequestService
{
    public function __construct(
        private readonly HighRiskPlatformChangePolicyService $highRiskPolicy = new HighRiskPlatformChangePolicyService(),
    ) {
    }

    public function request(
        Firm $firm,
        PlatformAdmin $requestedBy,
        SupportAccessType $accessType,
        string $reason,
        int $requestedDurationMinutes,
        ?string $emergencyJustification = null,
    ): SupportAccessRequest {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required for every support access request.');
        }

        if ($accessType === SupportAccessType::Emergency && trim((string) $emergencyJustification) === '') {
            throw new \InvalidArgumentException('Emergency support access requires an emergency_justification in addition to reason.');
        }

        $request = (new TenantContextService())->runWithFirmContext($firm, fn () => SupportAccessRequest::create([
            'firm_id' => $firm->id,
            'requested_by' => $requestedBy->id,
            'access_type' => $accessType,
            'reason' => $reason,
            'status' => SupportAccessRequestStatus::Requested,
            'requested_duration_minutes' => $requestedDurationMinutes,
            'emergency_justification' => $emergencyJustification,
        ]));

        if ($accessType === SupportAccessType::Emergency) {
            $this->highRiskPolicy->request(
                HighRiskChangeType::EmergencySupportAccess,
                $requestedBy,
                $reason,
                ['support_access_request_id' => $request->id],
            );
        }

        return $request;
    }

    /**
     * Whether a high_risk_change_requests row raised for this exact
     * SupportAccessRequest (via the metadata linkage) has reached
     * Approved. EmergencySupportAccess never requires a second
     * approval, so a single firstApprove() call is sufficient to
     * satisfy this.
     */
    public function isEmergencyHighRiskApproved(SupportAccessRequest $request): bool
    {
        return HighRiskChangeRequest::query()
            ->where('change_type', HighRiskChangeType::EmergencySupportAccess->value)
            ->where('status', HighRiskChangeRequestStatus::Approved->value)
            ->get()
            ->contains(fn (HighRiskChangeRequest $highRiskRequest) => (int) ($highRiskRequest->metadata['support_access_request_id'] ?? 0) === $request->id);
    }

    public function approve(SupportAccessRequest $request, FirmUser $approver): SupportAccessRequest
    {
        return (new TenantContextService())->runWithFirmContext($request->firm_id, function () use ($request, $approver) {
            $request->update([
                'status' => SupportAccessRequestStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $request->fresh();
        });
    }

    public function deny(SupportAccessRequest $request, FirmUser $denier): SupportAccessRequest
    {
        return (new TenantContextService())->runWithFirmContext($request->firm_id, function () use ($request, $denier) {
            $request->update([
                'status' => SupportAccessRequestStatus::Denied,
                'denied_by' => $denier->id,
                'denied_at' => now(),
            ]);

            return $request->fresh();
        });
    }

    public function expire(SupportAccessRequest $request): SupportAccessRequest
    {
        return (new TenantContextService())->runWithFirmContext($request->firm_id, function () use ($request) {
            $request->update(['status' => SupportAccessRequestStatus::Expired]);

            return $request->fresh();
        });
    }
}
