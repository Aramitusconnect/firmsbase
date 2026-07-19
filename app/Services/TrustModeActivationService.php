<?php

namespace App\Services;

use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Enums\TrustApprovalEventType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\HighRiskChangeRequest;
use App\Models\PlatformAdmin;
use App\Models\TrustApprovalEvent;
use App\ValueObjects\HighRiskChangeDecision;

/**
 * TrustModeActivationService — the ONLY Phase 13 service that talks to
 * the EXISTING, unmodified (except the approved optional-metadata
 * parameter) Phase 7 HighRiskPlatformChangePolicyService. It never
 * writes to high_risk_change_requests directly, and it never
 * re-implements two-person approval — that mechanism, and its
 * requirement that the second approver differ from the first, already
 * lives entirely in Phase 7.
 *
 * requestActivation() calls the existing request() with
 * HighRiskChangeType::TrustModeActivation and metadata=['firm_id' =>
 * $firm->id] so the request can later be traced back to a firm without
 * any schema change. linkApprovedActivation() is called AFTER the
 * existing Phase 7 flow reaches Approved (by two different platform
 * admins) — it verifies that firm-linkage and the Approved status
 * itself, then records exactly one TrustModeActivationLinked
 * trust_approval_events row, which is the one and only thing
 * TrustEligibilityService reads to decide "approved trust setup
 * exists." No automatic or one-person activation path exists anywhere
 * in this service.
 */
class TrustModeActivationService
{
    public function __construct(private readonly HighRiskPlatformChangePolicyService $highRiskPolicy)
    {
    }

    public function requestActivation(Firm $firm, PlatformAdmin $requestedBy, string $reason): HighRiskChangeRequest
    {
        return $this->highRiskPolicy->request(
            HighRiskChangeType::TrustModeActivation,
            $requestedBy,
            $reason,
            ['firm_id' => $firm->id],
        );
    }

    public function firstApprove(HighRiskChangeRequest $request, PlatformAdmin $approver): HighRiskChangeDecision
    {
        $this->assertIsTrustModeActivation($request);

        return $this->highRiskPolicy->firstApprove($request, $approver);
    }

    public function secondApprove(HighRiskChangeRequest $request, PlatformAdmin $approver): HighRiskChangeDecision
    {
        $this->assertIsTrustModeActivation($request);

        return $this->highRiskPolicy->secondApprove($request, $approver);
    }

    /**
     * Records the ONE trust_approval_events row that
     * TrustEligibilityService looks for. Requires the underlying Phase
     * 7 request to have already reached Approved (both approvals
     * completed by two different platform admins) and to be linked to
     * this exact firm via its metadata — never a firm the request
     * wasn't actually raised for.
     */
    public function linkApprovedActivation(Firm $firm, HighRiskChangeRequest $request, FirmUser $recordedBy): TrustApprovalEvent
    {
        $this->assertIsTrustModeActivation($request);

        if ($request->status !== HighRiskChangeRequestStatus::Approved) {
            throw new \RuntimeException('The high-risk change request must be fully (two-person) approved before trust mode can be linked as active.');
        }

        if ((int) ($request->metadata['firm_id'] ?? 0) !== $firm->id) {
            throw new \RuntimeException('This high-risk change request was not raised for this firm.');
        }

        return (new TenantContextService())->runWithFirmContext($firm, fn () => TrustApprovalEvent::create([
            'firm_id' => $firm->id,
            'event_type' => TrustApprovalEventType::TrustModeActivationLinked,
            'actor_firm_user_id' => $recordedBy->id,
            'high_risk_change_request_id' => $request->id,
        ]));
    }

    private function assertIsTrustModeActivation(HighRiskChangeRequest $request): void
    {
        if ($request->change_type !== HighRiskChangeType::TrustModeActivation) {
            throw new \RuntimeException('This high-risk change request is not a trust_mode_activation request.');
        }
    }
}
