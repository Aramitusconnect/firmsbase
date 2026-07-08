<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * ProfessionalReviewGateMappingService — the final Section 37
 * professional-review gate (17 keys) evaluated against existing
 * architecture, services, tests, and gap-register entries. Purely
 * declarative and read-only — no behavior, no UI, no migration, no
 * duplicate execution-readiness system. Reuses
 * FinalExecutiveReadinessMappingService rather than building a second
 * execution-readiness gate; reuses GovernanceMappingResult/
 * GovernanceMappingStatus from the Section 25 cross-cutting package.
 *
 * GovernanceMappingStatus::NotApplicableYet is a real enum case
 * (confirmed by direct inspection); none of the 17 professional-review
 * gates below need it, since every gate has either direct behavior/
 * mapping evidence (Implemented) or partial evidence with an existing
 * gap/process reference (PartiallyImplemented) — no gate's surface is
 * entirely absent from the codebase.
 *
 * AWS inspection (this section) confirmed BOTH conditional gaps are
 * NOT warranted:
 *  - AI retrieval: AiRetrievalIsolationService::buildContext() performs
 *    HARD, pre-retrieval firm/matter scoping — it throws (never
 *    silently narrows or post-filters) on a cross-firm matter or a
 *    matter the user cannot fully access. Its own docblock states the
 *    project rule verbatim: "dedicated namespace/partition per firm,
 *    never a shared index filtered only by metadata." No vector/
 *    embedding/similarity-search backend exists anywhere in the
 *    repository (confirmed by direct search) — Phase 15 has no real
 *    retrieval backend yet, only this readiness/contract layer, which
 *    itself already hard-scopes.
 *  - Organization-level licensing/signature: OrgLicenseService reuses
 *    the exact same LicenseStatus enum and the exact same LicenseEvent
 *    model (explicitly documented as "shared with FirmLicense" via a
 *    polymorphic licensable_type) as firm-level licensing — not a
 *    parallel system. license_files (validated exclusively by the one
 *    canonical LicenseFileValidationService) already supports an
 *    organization_id/org_license_id owner path via the same table and
 *    the same validation code (confirmed by direct inspection) — no
 *    separate OrgLicenseValidationService/OrgSignatureService exists
 *    anywhere (confirmed by direct search).
 *
 * Every classification below was determined by direct inspection of
 * the real repository at the time this service was written.
 */
class ProfessionalReviewGateMappingService
{
    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function all(): array
    {
        return [
            'plan.no_duplicate_phase_contracts' => new GovernanceMappingResult(
                item_key: 'plan.no_duplicate_phase_contracts',
                item_label: 'No phase\'s data/behavior contract was duplicated by a later phase',
                owning_class: \App\Services\FinalExecutiveReadinessMappingService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Every one of Sections 25-36 explicitly reused the prior phase\'s canonical class rather than redeclaring it (ComplianceGapRegistryService is the single gap register; EntitlementService is the single entitlement resolver; PaymentClassificationService is the single classification decision point). FinalExecutiveReadinessMappingService::oneProductNoForkStrategy() already confirms Firm/EntitlementService/TenantContextResolver/LicenseFileValidationService each exist exactly once.',
            ),
            'plan.no_revision_style_sections_override_contracts' => new GovernanceMappingResult(
                item_key: 'plan.no_revision_style_sections_override_contracts',
                item_label: 'No later section silently overrode an earlier approved contract',
                owning_class: null,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Every change to an established contract found during this review\'s inspection (e.g. AiMode replacing Phase 1\'s stub values, the QualityGateFirewallTest scope broadening in Sections 29/31-36) is documented as an explicit, approved correction with its own docblock/report entry — never a silent, undocumented override.',
            ),
            'security.no_hidden_navigation_only_security' => new GovernanceMappingResult(
                item_key: 'security.no_hidden_navigation_only_security',
                item_label: 'Protected surfaces are never secured by hidden navigation alone',
                owning_class: \App\Services\PlatformStaffAccessPolicyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'No admin UI exists at all (app/Filament does not exist, confirmed across Sections 34-36) — there is no hidden-navigation surface to rely on in the first place. Every protected backend surface that DOES exist is gated by a real, independent backend check: PlatformStaffAccessPolicyService (role-based, named allow-lists per data category) and EntitlementService (module-level). This gate is not passed "from hidden UI alone" — it is passed because backend enforcement is real and UI does not exist to be relied upon.',
            ),
            'payments.no_payment_classification_or_ledger_bypass' => new GovernanceMappingResult(
                item_key: 'payments.no_payment_classification_or_ledger_bypass',
                item_label: 'No payment can bypass classification or the canonical payment ledger',
                owning_class: \App\Services\PaymentClassificationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PaymentClassificationService::classify() is the ONLY place classification is decided (project rule, confirmed by direct inspection); Payment is the ONE canonical ledger table (project rule 4: "must be reusable later by Phase 6 Stripe flows and Phase 13 trust accounting," a row exists for every attempt including blocked ones); PaymentApplicationService is the sole writer of paid_amount_cents on both invoices and installments, always derived from this canonical table.',
            ),
            'trust.no_trust_iolta_before_foundation_acceptance' => new GovernanceMappingResult(
                item_key: 'trust.no_trust_iolta_before_foundation_acceptance',
                item_label: 'No firm may use trust/IOLTA workflows before the trust accounting foundation is accepted',
                owning_class: \App\Services\TrustEligibilityService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'TrustEligibilityService::evaluate() requires ALL FIVE conditions (CustomerType::LawFirm, trust_iolta entitlement enabled, payment_mode operating_and_trust, trust_iolta_protection not false, and a completed two-person-approved TrustModeActivationLinked event) with no override that skips any one — "no automatic trust-mode activation" is a project rule, not configurable behavior. TrustPilotExitCriteriaService\'s 7-item checklist governs when the pilot itself may widen beyond immigration law.',
            ),
            'communications.no_sms_whatsapp_without_unrevoked_consent' => new GovernanceMappingResult(
                item_key: 'communications.no_sms_whatsapp_without_unrevoked_consent',
                item_label: 'No automated SMS/WhatsApp is sent without a granted, unrevoked consent record',
                owning_class: \App\Services\ConsentService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'ConsentService::isGranted() is checked by PaymentPlanDunningService::checkAndLog() directly, and by DocumentChaseService::checkAndLog() via NotificationEligibilityService (the shared consent/preference foundation), before either service ever queues a channel-specific reminder. ConsentService::revoke() is real and versioned (a paired CommunicationConsentEvent row every time), and isGranted() re-reads the current row on every call, so a revocation takes effect on the very next eligibility check — no cached/stale grant can be acted upon.',
            ),
            'systems.no_second_license_entitlement_signature_system' => new GovernanceMappingResult(
                item_key: 'systems.no_second_license_entitlement_signature_system',
                item_label: 'No parallel license, entitlement, or signature/certificate system exists',
                owning_class: \App\Services\LicenseFileValidationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'OrgLicenseService reuses the exact same LicenseStatus enum and the exact same LicenseEvent model (explicitly documented "shared with FirmLicense" via a polymorphic licensable_type) as firm-level licensing. license_files supports an organization_id/org_license_id owner path on the SAME table, validated by the ONE canonical LicenseFileValidationService — no OrgLicenseValidationService/OrgSignatureService/OrganizationCertificate class exists anywhere (confirmed by direct search). EntitlementService remains the sole entitlement resolver for both firm- and org-sourced grants (EntitlementSource::OrgInherited is one of its four precedence-ranked sources, not a separate resolver). SignatureCertificateService likewise has no organization-level counterpart.',
            ),
            'entitlements.no_feature_flag_grants_access' => new GovernanceMappingResult(
                item_key: 'entitlements.no_feature_flag_grants_access',
                item_label: 'A feature flag may only restrict access an entitlement already grants, never widen it',
                owning_class: \App\Services\FeatureGateService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FeatureGateServiceTest::test_is_allowed_false_when_no_entitlement_exists_at_all() and test_is_allowed_true_when_entitlement_enabled_and_no_flags_exist() (Section 36 evidence) directly confirm a flag can never grant access beyond what EntitlementService already resolved — entitlements are documented as "the sole grant mechanism" (EntitlementSource enum docblock).',
            ),
            'legal_specialist.no_inappropriate_legal_language_without_configuration' => new GovernanceMappingResult(
                item_key: 'legal_specialist.no_inappropriate_legal_language_without_configuration',
                item_label: 'Legal-specialist (non-law-firm) customers never see law-firm/trust terminology without explicit configuration',
                owning_class: \App\Services\LegalSpecialistBoundaryPolicyService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'LegalSpecialistBoundaryPolicyService is real: it maintains a FORBIDDEN_TERMS list ("trust account", "IOLTA", "attorney", "law firm", etc.) and asserts a legal_specialist firm never has trust_iolta_protection enabled, and LegalSpecialistConsistencyMappingService confirms this policy is checked against Phase 16\'s own output surfaces (license file content, deployment config labels, health check strings). However, no broader customer-facing UI/wording surface exists yet to confirm end-to-end across every future output — real backend policy enforcement exists today, full customer-facing coverage cannot be confirmed until such a surface exists.',
            ),
            'legal_ai.no_customer_facing_auto_approval_or_filing_implication' => new GovernanceMappingResult(
                item_key: 'legal_ai.no_customer_facing_auto_approval_or_filing_implication',
                item_label: 'No AI or automated workflow implies automatic legal approval or filing',
                owning_class: \App\Services\AiApprovalWorkflowService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'AiApprovalWorkflowService::approve()/reject() require a real, role-restricted human actor (assertActorMayResolve() against a named APPROVAL_ROLES list) — no AI actor may resolve its own request, confirmed by direct inspection. No real e-filing/submission-to-court/agency integration exists anywhere in the repository (confirmed by direct search) — there is no mechanism that could imply automatic filing because no filing mechanism exists at all yet.',
            ),
            'ai.no_cross_firm_or_metadata_only_retrieval' => new GovernanceMappingResult(
                item_key: 'ai.no_cross_firm_or_metadata_only_retrieval',
                item_label: 'AI retrieval never crosses firm boundaries and never relies on post-retrieval metadata-only filtering',
                owning_class: \App\Services\AiRetrievalIsolationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'AiRetrievalIsolationService::buildContext() is the sole context builder (confirmed by direct search — no other service references AiRetrievalContext/buildContext), and it performs HARD, pre-retrieval scoping: it throws on any requested matter belonging to a different firm, and throws unless MatterAccessPolicyService confirms the user may access every requested matter — never a silent narrowing or a post-hoc filter. Its own docblock states the project rule verbatim: "dedicated namespace/partition per firm, never a shared index filtered only by metadata." No vector/embedding/similarity-search backend exists anywhere in the repository (confirmed by direct search) — this is the real, hard-scoping contract layer any future retrieval backend must call through; no gap is warranted.',
            ),
            'platform_roles.no_unrestricted_employee_access_by_default' => new GovernanceMappingResult(
                item_key: 'platform_roles.no_unrestricted_employee_access_by_default',
                item_label: 'No platform employee role has unrestricted access by default',
                owning_class: \App\Services\PlatformStaffAccessPolicyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PlatformStaffAccessPolicyService maintains named, per-category role allow-lists (CLIENT_AND_MATTER_DATA_ROLES, DOCUMENT_CONTENT_ROLES, PLATFORM_BILLING_ROLES, SECURITY_LOG_ROLES) — SalesRep/SalesManager/ReadOnlyAuditor are explicitly excluded from client/matter/document access, and SupportAgent/SecurityAuditor require governed, time-limited access even for the roles that can reach document content. SuperAdmin\'s broad standing access is still constrained for HIGH-RISK actions specifically: HighRiskPlatformChangePolicyService requires a non-empty reason and two-person approval (firstApprove/secondApprove) for every HighRiskChangeType, regardless of the requesting/approving admin\'s role.',
            ),
            'imports.no_production_write_without_preview_validation_confirmation' => new GovernanceMappingResult(
                item_key: 'imports.no_production_write_without_preview_validation_confirmation',
                item_label: 'No import batch writes a production record before preview, validation, and confirmation',
                owning_class: \App\Services\ImportApplyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'The real ImportBatchStatus sequence (Draft -> Staged -> Validated -> PreviewReady -> Confirmed -> Applying -> Applied) is enforced across dedicated services — ImportApplyService::apply() is the ONLY place production records (Client/Matter/Party/Document/TimeEntry) are created, and it runs only after confirmBatch(), which itself requires the batch to already be PreviewReady. ImportRollbackService provides the reverse path. Confirmed across Sections 32/33/35/36\'s independent inspections.',
            ),
            'templates.no_silent_template_upgrade_or_historical_draft_mutation' => new GovernanceMappingResult(
                item_key: 'templates.no_silent_template_upgrade_or_historical_draft_mutation',
                item_label: 'A template pack upgrade never silently alters an active matter, and a retired form edition never mutates a historical draft',
                owning_class: \App\Services\TemplatePackInstallationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Matter.pinned_template_pack_version_id is "set once at creation and never changed afterward" (Matter model docblock); TemplatePackInstallationService::markUpgradeAvailable() only flags availability, never auto-switches an active matter — applying requires a separate, explicit install() call. FormTemplateService::retire()\'s own docblock states "the ONLY write this method performs is on $version itself... no form_drafts row is ever read, queried, or updated here," and Tests\\Feature\\Phase10RetiredVersionPreservesHistoricalDraftsTest confirms both a pre-existing draft is unmutated and a new draft cannot be generated from a retired version.',
            ),
            'deployment.no_code_fork_or_connectivity_required_license_validation' => new GovernanceMappingResult(
                item_key: 'deployment.no_code_fork_or_connectivity_required_license_validation',
                item_label: 'Dedicated/private deployment is not a code fork, and license validation never requires network connectivity to keep an instance running',
                owning_class: \App\Services\LicenseFileValidationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Exactly one Firm/EntitlementService/TenantContextResolver/LicenseFileValidationService class exists for every deployment mode (SaaS/Dedicated/Private Enterprise), confirmed by FinalExecutiveReadinessMappingService::oneProductNoForkStrategy() — no per-mode fork exists anywhere. LicenseFileValidationService performs fully offline Ed25519/sodium signature verification with no network call of any kind (confirmed by direct inspection of the file\'s imports and body); grace/restricted transitions are computed locally from the signed payload\'s expiry, never requiring connectivity to keep an already-running instance operational.',
            ),
            'legal_data.no_destructive_cancellation_suspension_or_expiry' => new GovernanceMappingResult(
                item_key: 'legal_data.no_destructive_cancellation_suspension_or_expiry',
                item_label: 'No cancellation, suspension, or license expiry destroys legal records or blocks access outside governed offboarding/retention',
                owning_class: \App\Services\LegalDataAccessPolicyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'LegalDataAccessPolicyService guarantees read/export access remains available through every one of LicenseStatus::{Suspended, ExportOnly, Cancelled, Expired} — "Suspension must not destroy or hide legal data" (own docblock, quoting the exact project rule). LegalHoldService::hasActiveHold() is consulted by all three destructive-action governance services (DeletionGovernanceService, OffboardingRequestService, KeyDestructionRequestService), each returning a LegalHoldBlocked status rather than proceeding. DowngradeEvaluationService never deletes a user or legal data on a blocked downgrade (own docblock, confirmed Section 35).',
            ),
            'dedicated_deal.no_first_deal_before_fleet_and_offline_license_rehearsal' => new GovernanceMappingResult(
                item_key: 'dedicated_deal.no_first_deal_before_fleet_and_offline_license_rehearsal',
                item_label: 'No firm\'s first dedicated/private deal should close before fleet migration and offline license validation have been rehearsed',
                owning_class: \App\Services\FinalExecutiveReadinessMappingService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'The rehearsal CAPABILITY is real and tested: FleetMigrationOrchestrationServiceTest covers createRun()/begin()/applyInstance()/halt-on-failure/rollback(), and LicenseFileSigningAndValidationServiceTest covers offline signature verification and grace/restricted expiry sequencing — both already synthesized by FinalExecutiveReadinessMappingService (dedicated_private_deployment_modes, fleet_migration_offline_licensing_before_dedicated_deal). However, no code-enforced gate exists that BLOCKS a specific firm\'s dedicated-deal activation until a rehearsal has actually been run for that instance — this remains a human/business-process readiness step, not an automated one. This gate references FinalExecutiveReadinessMappingService rather than duplicating a second final-readiness system.',
            ),
        ];
    }

    public function byKey(string $key): ?GovernanceMappingResult
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function passed(): array
    {
        return $this->byStatus(GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function partial(): array
    {
        return $this->byStatus(GovernanceMappingStatus::PartiallyImplemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function failed(): array
    {
        return $this->byStatus(GovernanceMappingStatus::NotFound);
    }

    /**
     * None of the 17 professional-review gates are NotApplicableYet:
     * every gate either has direct behavior/mapping evidence
     * (Implemented) or partial evidence with an existing gap/process
     * reference (PartiallyImplemented) — no gate's surface is entirely
     * absent. GovernanceMappingStatus::NotApplicableYet DOES exist as
     * a real enum case (confirmed by direct inspection); this filters
     * on it directly rather than a notes marker, since no marker
     * workaround is needed here.
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function notApplicableYet(): array
    {
        return $this->byStatus(GovernanceMappingStatus::NotApplicableYet);
    }

    /**
     * Gates confirmed by direct, currently-enforced code behavior
     * (not merely a mapping/readiness declaration).
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function directBehaviorEnforced(): array
    {
        $keys = [
            'payments.no_payment_classification_or_ledger_bypass', 'trust.no_trust_iolta_before_foundation_acceptance',
            'communications.no_sms_whatsapp_without_unrevoked_consent', 'entitlements.no_feature_flag_grants_access',
            'legal_ai.no_customer_facing_auto_approval_or_filing_implication', 'ai.no_cross_firm_or_metadata_only_retrieval',
            'platform_roles.no_unrestricted_employee_access_by_default',
            'imports.no_production_write_without_preview_validation_confirmation',
            'templates.no_silent_template_upgrade_or_historical_draft_mutation',
            'deployment.no_code_fork_or_connectivity_required_license_validation',
            'legal_data.no_destructive_cancellation_suspension_or_expiry',
            'systems.no_second_license_entitlement_signature_system',
        ];

        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * Gates confirmed primarily via mapping/readiness-service synthesis
     * rather than a single, direct behavior test.
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function mappingReadinessEvidence(): array
    {
        $keys = [
            'plan.no_duplicate_phase_contracts', 'plan.no_revision_style_sections_override_contracts',
            'security.no_hidden_navigation_only_security', 'legal_specialist.no_inappropriate_legal_language_without_configuration',
            'dedicated_deal.no_first_deal_before_fleet_and_offline_license_rehearsal',
        ];

        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function existingGapCrossReferences(): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->status === GovernanceMappingStatus::PartiallyImplemented,
        );
    }

    /**
     * True security/data-integrity blockers only.
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function productionBlockers(): array
    {
        return [];
    }

    /**
     * Gates that must resolve before a dedicated/private deal closes.
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function dedicatedPrivateDealBlockers(): array
    {
        $keys = ['dedicated_deal.no_first_deal_before_fleet_and_offline_license_rehearsal'];

        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function legalDataPreservation(): array
    {
        $keys = ['legal_data.no_destructive_cancellation_suspension_or_expiry'];

        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function securityEntitlementTrustAiImportTemplateDeployment(): array
    {
        $keys = [
            'security.no_hidden_navigation_only_security', 'entitlements.no_feature_flag_grants_access',
            'systems.no_second_license_entitlement_signature_system', 'trust.no_trust_iolta_before_foundation_acceptance',
            'ai.no_cross_firm_or_metadata_only_retrieval', 'imports.no_production_write_without_preview_validation_confirmation',
            'templates.no_silent_template_upgrade_or_historical_draft_mutation',
            'deployment.no_code_fork_or_connectivity_required_license_validation',
        ];

        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * @return array{clear_for_execution: bool, passed_count: int, partial_count: int, failed_count: int, not_applicable_yet_count: int, production_blockers: array<string, GovernanceMappingResult>, dedicated_private_deal_blockers: array<string, GovernanceMappingResult>, referenced_gaps: array<int, string>, notes: string}
     */
    public function overallGateStatus(): array
    {
        $passedCount = count($this->passed());
        $partialCount = count($this->partial());
        $failedCount = count($this->failed());
        $notApplicableCount = count($this->notApplicableYet());

        return [
            'clear_for_execution' => $failedCount === 0,
            'passed_count' => $passedCount,
            'partial_count' => $partialCount,
            'failed_count' => $failedCount,
            'not_applicable_yet_count' => $notApplicableCount,
            'production_blockers' => $this->productionBlockers(),
            'dedicated_private_deal_blockers' => $this->dedicatedPrivateDealBlockers(),
            'referenced_gaps' => array_values(array_unique($this->extractReferencedGapKeys())),
            'notes' => sprintf(
                '%d/17 gates fully passed, %d partially passed (cross-referencing existing gaps or pending human/process readiness), %d failed, %d not applicable yet. No new gap was warranted by this section\'s AI-retrieval or organization-level-licensing inspections. clear_for_execution reflects only true failures — PartiallyImplemented gates already reference their owning gap/readiness path and do not block execution by themselves.',
                $passedCount,
                $partialCount,
                $failedCount,
                $notApplicableCount,
            ),
        ];
    }

    /**
     * Gap candidates from this section. Empty: AWS confirmed both
     * conditional gaps (AI retrieval hard-scope, duplicate org-level
     * license/signature system) do not apply.
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function gaps(): array
    {
        return [];
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function byStatus(GovernanceMappingStatus $status): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->status === $status,
        );
    }

    /**
     * @return array<int, string>
     */
    private function extractReferencedGapKeys(): array
    {
        $knownGapKeys = [
            'rls_prepared_not_enforced', 'signed_document_url_service_missing',
            'real_malware_scanning_engine_stubbed', 'emergency_support_access_high_risk_approval_not_wired',
            'ai_jobs_not_cancelled_when_entitlement_removed', 'stripe_disconnect_payment_collection_block_not_enforced',
            'template_language_fallback_staff_notification_missing', 'form_edition_watch_sla_controls_missing',
            'trust_ledger_entry_posting_actor_not_guaranteed', 'org_admin_role_missing',
        ];

        $found = [];

        foreach ($this->all() as $item) {
            foreach ($knownGapKeys as $gapKey) {
                if (str_contains($item->notes, $gapKey)) {
                    $found[] = $gapKey;
                }
            }
        }

        return $found;
    }
}
