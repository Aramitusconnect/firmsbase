<?php

namespace App\Services;

use App\Models\Firm;

/**
 * TrustJurisdictionReadinessService — a static, checklist-only service
 * mirroring SignatureEsignLegalReadinessService's exact pattern
 * (correction #16). It reads firm_settings.state_jurisdiction ONLY as
 * reference metadata surfaced to a human reviewer; it makes NO
 * compliance claim, resolves NO jurisdiction-specific rule automatically,
 * and gates NOTHING by itself. State-bar-specific IOLTA rules
 * (three-way reconciliation cadence, permitted-bank lists, signature-
 * card requirements, interest-remittance program participation, and
 * similar) are explicitly OUT OF SCOPE of this phase and are NOT
 * implemented here — the exact phase/gate that will own jurisdiction-
 * specific automated enforcement has not yet been assigned, and until
 * it is, this service's checklist remains advisory-only.
 */
class TrustJurisdictionReadinessService
{
    /**
     * Each item names a jurisdiction-specific concern a firm's own
     * compliance review must confirm before relying on this system for
     * that state's trust accounting — this system does not verify any
     * of these itself.
     */
    public const REQUIRED_REVIEW_ITEMS = [
        'state_bar_iolta_program_registration',
        'three_way_reconciliation_cadence_requirement',
        'permitted_bank_or_depository_list',
        'signature_card_and_authorized_signer_requirements',
        'interest_remittance_program_participation',
        'record_retention_period_requirement',
        'client_notice_and_disclosure_requirements',
    ];

    /**
     * Returns the static checklist together with the firm's configured
     * reference jurisdiction (read-only) for a human reviewer to work
     * from. Does not itself assert compliance.
     */
    public function checklistFor(Firm $firm): array
    {
        return [
            'firm_id' => $firm->id,
            'reference_state_jurisdiction' => $firm->firmSettings?->state_jurisdiction,
            'review_items' => self::REQUIRED_REVIEW_ITEMS,
            'compliance_claim_made' => false,
        ];
    }
}
