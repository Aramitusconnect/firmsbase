<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\PaymentMode;
use App\Enums\TrustApprovalEventType;
use App\Models\Firm;
use App\Models\TrustApprovalEvent;
use App\ValueObjects\TrustEligibilityDecision;

/**
 * TrustEligibilityService — the single gate every other Phase 13
 * service calls first (approved correction #9). All five conditions
 * must hold; there is no override that skips any one of them, since
 * "no automatic trust-mode activation" and "no one-person production
 * activation" are project rules, not configurable behavior.
 *
 *   1. firm.customer_type === CustomerType::LawFirm (legal_specialist
 *      is always blocked, checked first, fails closed).
 *   2. The existing `trust_iolta` entitlement resolves enabled via the
 *      existing, unmodified EntitlementService.
 *   3. firm.firmSettings.payment_mode === PaymentMode::OperatingAndTrust
 *      — the EXISTING enum case (Phase 1), not a new one.
 *   4. firm.firmSettings.trust_iolta_protection !== false — the
 *      existing, previously-unused safety flag (defaults true).
 *   5. Approved trust setup: at least one trust_approval_events row of
 *      type TrustModeActivationLinked whose linked, EXISTING Phase 7
 *      HighRiskChangeRequest has status === Approved — read-only check
 *      against Phase 7's table, never a duplicate approval mechanism.
 */
class TrustEligibilityService
{
    private const MODULE_CODE = 'trust_iolta';

    public function __construct(private readonly EntitlementService $entitlementService)
    {
    }

    public function evaluate(Firm $firm): TrustEligibilityDecision
    {
        if ($firm->customer_type !== CustomerType::LawFirm) {
            return TrustEligibilityDecision::deny('Trust/IOLTA workflows are only available to law_firm customers; legal_specialist is always blocked.');
        }

        if (! $this->entitlementService->isEnabled($firm->id, self::MODULE_CODE)) {
            return TrustEligibilityDecision::deny('The trust_iolta entitlement is not enabled for this firm.');
        }

        // firm_settings is FORCE-RLS-protected as of this checkpoint. None
        // of the ~25 call sites across the 7 Trust services that reach this
        // gate (assertEligible()/isEligible()/evaluate()) establish their
        // own tenant context first, so the read must be self-wrapped here,
        // scoped tightly to resolving $settings only — not the whole
        // method, since the other checks (customer_type, entitlement,
        // hasApprovedTrustSetup) don't touch firm_settings and shouldn't be
        // pulled inside the wrap.
        $settings = (new TenantContextService())->runWithFirmContext($firm, fn () => $firm->firmSettings);

        if ($settings?->payment_mode !== PaymentMode::OperatingAndTrust) {
            return TrustEligibilityDecision::deny('Firm payment_mode is not operating_and_trust.');
        }

        if ($settings->trust_iolta_protection === false) {
            return TrustEligibilityDecision::deny('Firm has explicitly disabled trust_iolta_protection.');
        }

        if (! $this->hasApprovedTrustSetup($firm)) {
            return TrustEligibilityDecision::deny('No approved trust-mode activation exists for this firm (Phase 7 two-person approval not completed).');
        }

        return TrustEligibilityDecision::allow();
    }

    public function isEligible(Firm $firm): bool
    {
        return $this->evaluate($firm)->allowed;
    }

    public function assertEligible(Firm $firm): void
    {
        $decision = $this->evaluate($firm);

        if (! $decision->allowed) {
            throw new \RuntimeException($decision->reason);
        }
    }

    /**
     * Reads (never writes) a TrustModeActivationLinked event for this
     * firm and checks that the Phase 7 HighRiskChangeRequest it
     * references has actually reached Approved — i.e. the existing
     * two-person platform-admin flow really completed. No automatic or
     * one-person activation path exists.
     */
    private function hasApprovedTrustSetup(Firm $firm): bool
    {
        return TrustApprovalEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', TrustApprovalEventType::TrustModeActivationLinked->value)
            ->whereHas('highRiskChangeRequest', function ($query) {
                $query->where('status', HighRiskChangeRequestStatus::Approved->value);
            })
            ->exists();
    }
}
