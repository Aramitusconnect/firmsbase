<?php

namespace App\Services;

use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Models\Firm;
use App\Models\HighRiskChangeRequest;
use App\Models\PlatformAdmin;
use App\ValueObjects\HighRiskChangeDecision;

/**
 * DedicatedCustomerTypeApprovalService — mirrors TrustModeActivationService
 * (Phase 13) EXACTLY: the ONLY Phase 16 service that talks to the
 * EXISTING, unmodified HighRiskPlatformChangePolicyService. It never
 * writes to high_risk_change_requests directly and never
 * re-implements two-person approval.
 *
 * Gates project rule 17: dedicated + legal_specialist requires an
 * Approved HighRiskChangeType::DedicatedLegalSpecialistApproval
 * request linked to this exact firm; dedicated + law_firm needs no
 * such gate at all.
 */
class DedicatedCustomerTypeApprovalService
{
    public function __construct(private readonly HighRiskPlatformChangePolicyService $highRiskPolicy)
    {
    }

    public function requestApproval(Firm $firm, PlatformAdmin $requestedBy, string $reason): HighRiskChangeRequest
    {
        return $this->highRiskPolicy->request(
            HighRiskChangeType::DedicatedLegalSpecialistApproval,
            $requestedBy,
            $reason,
            ['firm_id' => $firm->id],
        );
    }

    public function firstApprove(HighRiskChangeRequest $request, PlatformAdmin $approver): HighRiskChangeDecision
    {
        $this->assertIsDedicatedLegalSpecialistApproval($request);

        return $this->highRiskPolicy->firstApprove($request, $approver);
    }

    public function secondApprove(HighRiskChangeRequest $request, PlatformAdmin $approver): HighRiskChangeDecision
    {
        $this->assertIsDedicatedLegalSpecialistApproval($request);

        return $this->highRiskPolicy->secondApprove($request, $approver);
    }

    /**
     * True only if an Approved request of this type, linked to this
     * exact firm via metadata, exists.
     */
    public function isApproved(Firm $firm): bool
    {
        return HighRiskChangeRequest::query()
            ->where('change_type', HighRiskChangeType::DedicatedLegalSpecialistApproval->value)
            ->where('status', HighRiskChangeRequestStatus::Approved->value)
            ->get()
            ->contains(fn (HighRiskChangeRequest $request) => (int) ($request->metadata['firm_id'] ?? 0) === $firm->id);
    }

    private function assertIsDedicatedLegalSpecialistApproval(HighRiskChangeRequest $request): void
    {
        if ($request->change_type !== HighRiskChangeType::DedicatedLegalSpecialistApproval) {
            throw new \RuntimeException('This high-risk change request is not a dedicated_legal_specialist_approval request.');
        }
    }
}
