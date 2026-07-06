<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * ComplianceReviewGateMappingService — declares the 10 cross-cutting
 * compliance/legal-review items from the master plan and maps each to
 * an EXISTING owning readiness/governance mechanism (Phases 1-17).
 * Purely declarative — no new compliance logic, no new legal
 * conclusion engine, no new table.
 */
class ComplianceReviewGateMappingService
{
    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function all(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'trust_iolta_jurisdiction_review',
                item_label: 'Trust/IOLTA jurisdiction-specific compliance review',
                owning_class: \App\Services\TrustJurisdictionReadinessService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'checklistFor() surfaces a static, advisory-only review-items checklist (state bar IOLTA registration, three-way reconciliation cadence, permitted banks, etc.) alongside the firm\'s reference jurisdiction. Makes no compliance claim itself — human review is required, by design.',
            ),
            new GovernanceMappingResult(
                item_key: 'esign_ueta_evidence_review',
                item_label: 'ESIGN/UETA electronic-signature legal evidence review',
                owning_class: \App\Services\SignatureEsignLegalReadinessService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'checklist() enumerates intent-to-sign, consumer disclosure/consent, retention capability, tamper-evidence, signature-record association, and jurisdiction review. SignatureCertificateService provides the actual tamper-evident signed record backing the "tamper_evidence" check.',
            ),
            new GovernanceMappingResult(
                item_key: 'communication_consent_email_sms_whatsapp_portal',
                item_label: 'Communication consent captured across email/SMS/WhatsApp/portal channels',
                owning_class: \App\Services\ConsentService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'ConsentChannel enum covers exactly these four channels (Email, Sms, WhatsApp, Portal); ConsentService::capture()/revoke()/isGranted() are channel-generic and apply identically to all four.',
            ),
            new GovernanceMappingResult(
                item_key: 'tcpa_automated_sms_exposure',
                item_label: 'TCPA exposure review for automated/programmatic SMS sending',
                owning_class: \App\Services\ConsentService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'SMS sending is gated generically by ConsentService::isGranted() for ConsentChannel::Sms, but no TCPA-specific automated-dialer/prior-express-consent checklist exists (unlike TrustJurisdictionReadinessService\'s dedicated advisory pattern). No literal "TCPA" reference exists anywhere in the codebase.',
            ),
            new GovernanceMappingResult(
                item_key: 'consent_records_captured_versioned_enforced',
                item_label: 'Consent records captured, versioned, and enforced before use',
                owning_class: \App\Services\ConsentService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Every capture()/revoke() call records consent_text_version and writes a paired CommunicationConsentEvent row; isGranted() is the enforcement check any future notification-sending code must call first.',
            ),
            new GovernanceMappingResult(
                item_key: 'imputed_conflict_firm_default_org_opt_in',
                item_label: 'Imputed conflict checking: firm-scoped by default, organization-wide only by explicit opt-in',
                owning_class: \App\Services\ConflictCheckService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'resolveScope() defaults to ConflictCheckScope::Firm; only widens to Organization when the firm\'s Organization::conflict_scope is already explicitly ConflictScope::OrganizationWide — never widens implicitly, and even then only reaches sibling firms under the same organization.',
            ),
            new GovernanceMappingResult(
                item_key: 'vendor_subprocessor_register_disclosures',
                item_label: 'Vendor and subprocessor register with disclosure tracking',
                owning_class: \App\Services\VendorRegisterService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Vendor (table vendor_register) tracks risk_level/dpa_status/soc_report_status/ai_zero_retention_status; Subprocessor tracks customer-facing disclosure entries (is_publicly_disclosed, disclosure_effective_at) linked to a Vendor.',
            ),
            new GovernanceMappingResult(
                item_key: 'ai_provider_zero_retention_terms',
                item_label: 'AI provider zero-data-retention contractual terms tracked',
                owning_class: \App\Models\Vendor::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'Vendor::ai_zero_retention_status (VendorAiZeroRetentionStatus: NotApplicable/RequiredNotConfirmed/Confirmed) records whether zero-retention terms are confirmed per AI vendor. This is a tracked declaration a human reviewer sets — no automated verification against the actual provider contract exists.',
            ),
            new GovernanceMappingResult(
                item_key: 'retention_legal_hold_export_deletion_key_destruction_offboarding',
                item_label: 'Full offboarding chain: retention, legal hold, export, deletion, key destruction',
                owning_class: \App\Services\OffboardingRequestService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Phase 17: RetentionPolicyService (clearance) + LegalHoldService (blocking holds) + OffboardingExportService (verified export) + DeletionGovernanceService (governed deletion request) + KeyDestructionApprovalService (irreversible key destruction), sequenced by OffboardingRequestService::advance(). Fully tested end-to-end.',
            ),
            new GovernanceMappingResult(
                item_key: 'ai_disclaimers_human_review_firm_keys_data_usage_logging_provider_terms',
                item_label: 'AI governance: disclaimers, human review, firm-owned keys, usage logging, provider terms',
                owning_class: \App\Services\AiApprovalWorkflowService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Composite item, each sub-concern has a real owning mechanism: disclaimers via AiApprovalRequest::draft_label ("ai_generated_draft", always set); human review via AiApprovalWorkflowService::submit()/approve()/reject() gating every high-risk category before use; firm-owned keys via FirmAiProviderKey (envelope-encrypted per firm/provider); usage logging via AiUsageEvent (append-only); provider terms via Vendor::ai_zero_retention_status.',
            ),
        ];
    }

    public function byKey(string $key): ?GovernanceMappingResult
    {
        foreach ($this->all() as $item) {
            if ($item->item_key === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<int, GovernanceMappingResult> every item not classified Implemented
     */
    public function gaps(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->status !== GovernanceMappingStatus::Implemented,
        ));
    }
}
