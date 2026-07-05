<?php

namespace App\Services;

use App\Models\Firm;

/**
 * TrustPilotExitCriteriaService — a static, checklist-only service
 * documenting what must be true before trust accounting is considered
 * ready to expand beyond the immigration-law pilot to another practice
 * area or a broader rollout (correction #16). isReadyForVerticalExpansion()
 * reports the checklist result; it does NOT itself gate any
 * feature, route, entitlement, or module activation — gating remains
 * the exclusive responsibility of TrustEligibilityService (per-firm
 * activation) and the entitlement/module_catalog system (per-module
 * availability). This service is read-only reporting for humans
 * deciding when to widen the rollout.
 */
class TrustPilotExitCriteriaService
{
    public const EXIT_CRITERIA = [
        'trust_mode_activation_two_person_approval_working_in_production',
        'zero_negative_balance_incidents_during_pilot_window',
        'zero_cross_matter_fund_use_incidents_during_pilot_window',
        'at_least_one_completed_bank_reconciliation_with_zero_discrepancy',
        'refund_and_chargeback_workflows_exercised_at_least_once',
        'jurisdiction_readiness_checklist_reviewed_by_firm_compliance_contact',
        'no_open_high_risk_adjustment_requests_older_than_review_window',
    ];

    /**
     * Returns the static checklist for a given firm together with which
     * items are objectively measurable from this system's own data
     * (e.g. reconciliation/discrepancy counts) versus which require an
     * external human attestation (e.g. compliance contact review). This
     * method makes no automatic determination of overall readiness —
     * it is a reporting aid, not a gate.
     */
    public function checklistFor(Firm $firm): array
    {
        return [
            'firm_id' => $firm->id,
            'exit_criteria' => self::EXIT_CRITERIA,
            'gates_anything_automatically' => false,
        ];
    }
}
