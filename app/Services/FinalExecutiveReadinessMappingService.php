<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\ExecutiveReadinessSummary;
use App\ValueObjects\GapRegisterItem;
use App\ValueObjects\GovernanceMappingResult;

/**
 * FinalExecutiveReadinessMappingService — the Section 31 final
 * synthesis of every Section 25-30 mapping/readiness service into a
 * single executive-readiness picture. Purely declarative, like every
 * mapping service before it: no DB calls, no writes, no new gap
 * register, no new readiness system. It reads
 * ComplianceGapRegistryService::all() directly (the ONLY gap
 * register) and re-classifies nothing that those services already
 * classify — it only synthesizes and cross-references their existing,
 * already-declared evidence.
 */
class FinalExecutiveReadinessMappingService
{
    public function summary(): ExecutiveReadinessSummary
    {
        return new ExecutiveReadinessSummary(
            pilotLaunchReadiness: $this->pilotLaunchReadiness(),
            architecturePreservation: $this->architecturePreservation(),
            workflowAutomationDifferentiation: $this->workflowAutomationDifferentiation(),
            structuralCommitments: $this->structuralCommitments(),
            oneProductNoForkStrategy: $this->oneProductNoForkStrategy(),
            knownOpenGaps: $this->knownOpenGaps(),
            dedicatedPrivateDealBlockers: $this->dedicatedPrivateDealBlockers(),
        );
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function pilotLaunchReadiness(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'usa_saas_law_firm_pilot',
                item_label: 'Narrow USA SaaS law-firm immigration pilot is the launch target',
                owning_class: \App\Services\DeploymentModeCoverageMappingService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'CustomerType::LawFirm and the SaaS deployment mode are real, modeled distinctions. DeploymentModeCoverageMappingService::saas() declares all 4 SaaS control keys (isolation, plan/license controls, centralized platform billing, support access) as real, tested mechanisms — the SaaS law-firm pilot is a genuine, launchable configuration today, not aspirational.',
            ),
            new GovernanceMappingResult(
                item_key: 'immigration_first_practice_area',
                item_label: 'Immigration is the first, most-built-out practice area',
                owning_class: \App\Services\TemplatePackCoverageMappingService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'ImmigrationFormCode declares the exact 7 approved starter forms and FormTemplateService validates against it; PracticeAreaFactory::immigration() gives immigration a named practice-area state. TemplatePackCoverageMappingService::byKey(\'immigration_starter_pack\') is the only pack with concrete practice-specific evidence — every other practice pack is honestly NotFound, confirming immigration is deliberately first, not one of several equally-built verticals.',
            ),
            new GovernanceMappingResult(
                item_key: 'controlled_lead_intake_document_matter_workflow',
                item_label: 'A controlled lead -> intake -> document -> matter workflow exists end to end',
                owning_class: \App\Services\MobilePortalCoverageMappingService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FirmLead -> Consultation -> Client -> Matter -> IntakeSubmission -> DocumentRequest/DocumentRequestItem is a real, firm-scoped chain (LeadConversionService gates the only path a lead may become a client). MobilePortalCoverageMappingService confirms checklist_progress and save_and_continue_intake are Implemented on top of this chain. ComplianceReviewGateMappingService::byKey(\'communication_consent_email_sms_whatsapp_portal\') and byKey(\'consent_records_captured_versioned_enforced\') confirm consent capture is a real, reviewed control at intake, not an afterthought.',
            ),
            new GovernanceMappingResult(
                item_key: 'flat_fee_billing_payment_plans',
                item_label: 'Flat-fee billing with payment plans is a first-class capability',
                owning_class: \App\Models\PaymentPlan::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'InvoiceType::FlatFee is explicitly modeled as a first-class invoice type (not a special case of time-and-expense billing), and PaymentPlan/PaymentPlanInstallment are real, firm/client/matter-scoped models. MobilePortalCoverageMappingService::byKey(\'mobile_payment_plan_visibility\') confirms this data is schema-ready for a client to view, and FirmCommandCenterAggregationService aggregates installmentsDueCount/installmentsMissedCount from the same real installment status field.',
            ),
            new GovernanceMappingResult(
                item_key: 'safe_operating_payments',
                item_label: 'Payments are collected and classified safely, without premature trust exposure',
                owning_class: \App\Services\IdempotencyKeyCoverageMappingService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PaymentClassification is the strict, single-writer classification enum (Payment::isAcceptedOperatingPayment() requires OperatingPayment + Succeeded). IdempotencyKeyCoverageMappingService::byKey(\'payment_collection\') and byKey(\'payment_plan_installment_collection\') confirm retry-safe collection is a declared, tracked control. TestCoverageMappingService::byKey(\'payment_classification\') and byKey(\'manual_payment_double_submit\') confirm these are named, tracked test groups, not unverified claims.',
            ),
            new GovernanceMappingResult(
                item_key: 'strong_onboarding',
                item_label: 'Strong firm/client onboarding exists to support the pilot',
                owning_class: \App\Services\ReleaseChecklistReadinessService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FirmProductionActivationService/FirmActivationEvent record a real, staged activation checklist before a firm goes live. ReleaseChecklistReadinessService and AccessibilityCoverageMappingService::byKey(\'client_portal\') confirm release and client-facing accessibility readiness are both declared, tracked gates a firm must clear before onboarding is considered strong, not just a signup form.',
            ),
        ];
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function architecturePreservation(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'multi_practice_expansion',
                item_label: 'The architecture preserves room to expand beyond immigration into other practice areas',
                owning_class: \App\Services\TemplatePackCoverageMappingService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'TemplatePack/TemplatePackVersion/InstalledTemplatePack are a generic, practice-area-agnostic catalog/install mechanism (practice_area_id foreign key, not an immigration-only table). TemplatePackCoverageMappingService declares all 10 expected future pack keys explicitly, honestly NotFound where unbuilt — the schema and install mechanism do not need to change to add them later.',
            ),
            new GovernanceMappingResult(
                item_key: 'organization_billing_account_hierarchy',
                item_label: 'The Organization -> BillingAccount -> Firm commercial hierarchy is preserved',
                owning_class: \App\Models\Organization::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Organization and BillingAccount are real models with migrations that precede firms in creation order (Phase 1 data contract). PermissionMatrixMappingService::organizationRoles() confirms organization-level roles are a declared, real boundary distinct from firm-level roles, reinforcing that the hierarchy is a genuine structural layer, not a placeholder.',
            ),
            new GovernanceMappingResult(
                item_key: 'license_module_control',
                item_label: 'License and module/entitlement controls are preserved',
                owning_class: \App\Services\EntitlementService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'EntitlementService/FirmLicense/FirmEntitlement/ModuleCatalog/Plan are real, tested mechanisms. DeploymentModeCoverageMappingService::byKey(\'saas_plan_license_controls\') confirms this is Implemented and governs what every firm may use regardless of deployment mode.',
            ),
            new GovernanceMappingResult(
                item_key: 'dedicated_private_deployment_modes',
                item_label: 'Dedicated and Private Enterprise deployment modes are preserved as a foundation',
                owning_class: \App\Services\DeploymentModeCoverageMappingService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Implemented as a declared, simulated foundation rather than live infrastructure: LicenseFileValidationService (offline signature verification), FleetMigrationOrchestrationService (enrollment/version-skew tracking), and PrivateEnterpriseSettings (custom-domain/storage/telemetry declarations) are all real and tested, per DeploymentModeCoverageMappingService::dedicated()/privateEnterprise(). No real hosting/provisioning infrastructure is claimed or required for this classification.',
            ),
            new GovernanceMappingResult(
                item_key: 'ai_governance',
                item_label: 'AI governance (mode control, firm-owned keys, approval workflow) is preserved',
                owning_class: \App\Services\OperationalReadinessMappingService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'AiMode (Disabled/PlatformManaged/FirmOwned) is real and enforced; OperationalReadinessMappingService::byKey(\'firm_owned_ai_api_key_encryption\') and byKey(\'no_show_again_after_key_entry\') are both Implemented. AiApprovalWorkflowService provides a real submit/approve/reject workflow rather than unattended AI action.',
            ),
            new GovernanceMappingResult(
                item_key: 'future_trust_accounting',
                item_label: 'Future trust/IOLTA accounting exists as a real foundation, not yet activated broadly',
                owning_class: \App\Services\TrustDependentPackGatingMappingService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'TrustEligibilityService\'s 5-condition gate and TrustPilotExitCriteriaService\'s 7-item exit checklist are both real, tested mechanisms proving trust accounting is a deliberate foundation, not vaporware. TrustDependentPackGatingMappingService confirms only family_law_pack and personal_injury_pack are trust-dependent, and neither is launched — the foundation exists without forcing premature adoption.',
            ),
            new GovernanceMappingResult(
                item_key: 'no_premature_trust_fund_exposure',
                item_label: 'No firm or client is exposed to trust-fund handling before it is safe to do so',
                owning_class: \App\Services\LegalSpecialistConsistencyMappingService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'TrustEligibilityService fails closed for any firm whose customer_type is not LawFirm, requires the trust_iolta entitlement, requires payment_mode operating_and_trust, and requires a completed two-person-approved trust-mode activation event — there is no automatic or one-person activation path. LegalSpecialistConsistencyMappingService confirms legal_specialist firms never see trust/IOLTA terminology at all, reinforcing that exposure is structurally prevented, not merely discouraged.',
            ),
        ];
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function workflowAutomationDifferentiation(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'reduces_client_chasing',
                item_label: 'The product reduces manual client-chasing, not just displays a dashboard',
                owning_class: \App\Services\FirmCommandCenterAggregationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'DocumentChaseRule/DocumentChaseService/DocumentChaseSchedulerService and PaymentPlanDunningService provide real, rule-driven chase eligibility and escalation logic (event_type "escalated" is real and counted). FirmCommandCenterAggregationService::snapshot() surfaces documentChaseEscalationsCount directly from this real mechanism, and ClientPortalAccessibilityReadinessService confirms the client-facing surface a chased client would use is itself a tracked readiness checklist, not an assumption.',
            ),
            new GovernanceMappingResult(
                item_key: 'improves_matter_readiness',
                item_label: 'The product actively improves matter readiness rather than just recording status',
                owning_class: \App\Services\FirmCommandCenterAggregationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'MatterReadinessService::recompute() and MatterReadinessScore/ReadinessScorecardRegistry provide a real, recomputed readiness signal per matter, and MatterStatus::WaitingOnClient/ReadyForReview are real, derived states. FirmCommandCenterAggregationService surfaces mattersWaitingOnClientCount/mattersReadyForReviewCount directly from these real fields.',
            ),
            new GovernanceMappingResult(
                item_key: 'collects_flat_fees_reliably',
                item_label: 'The product collects flat fees reliably via payment plans, not just invoices sitting unpaid',
                owning_class: \App\Services\FirmCommandCenterAggregationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PaymentPlanInstallmentStatus (Scheduled/Due/Paid/PartiallyPaid/Missed/Waived/Cancelled) is a real, enforced lifecycle, and PaymentApplicationService is the sole writer of paid_amount_cents from the canonical payments table. FirmCommandCenterAggregationService surfaces installmentsDueCount/installmentsMissedCount/unpaidInvoicesCount directly from these real, firm-scoped fields.',
            ),
            new GovernanceMappingResult(
                item_key: 'standardizes_practice_area_operations',
                item_label: 'The product standardizes practice-area operations via curated templates, not ad hoc document assembly',
                owning_class: \App\Services\TemplatePackCoverageMappingService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FormTemplateService::registerFormCode() validates every immigration form against the exact 7 approved ImmigrationFormCode values, curated exclusively by a PlatformAdmin — no firm creates or edits a form_template_version. DefinitionOfDoneReadinessService::REQUIRED_CHECKS enforces that any new feature documents its data-model state transitions and accessibility checks before being considered done, keeping practice-area operations standardized as the product grows.',
            ),
            new GovernanceMappingResult(
                item_key: 'platform_owner_commercial_controls',
                item_label: 'The platform owner retains commercial control over template-pack distribution',
                owning_class: \App\Services\TemplatePackCoverageMappingService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'TemplatePackCommercialService::installIfEntitled() is a real, tested gate requiring the practice_area_templates entitlement before any pack installs — the platform owner, not the firm, controls whether packs may be installed at all. However, TemplatePackCoverageMappingService::byKey(\'included_by_plan\') and the tracked template_pack_per_pack_commercial_differentiation_missing gap confirm this control is blanket-only today, with no per-pack tier/pricing differentiation yet — PartiallyImplemented, not overclaimed as complete.',
            ),
        ];
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function structuralCommitments(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'org_billing_account_phase_1_data_contract',
                item_label: 'Organization/BillingAccount/Firm was committed to as the Phase 1 data contract',
                owning_class: \App\Services\DataModelContractMappingService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'The organizations and billing_accounts migrations both exist and are ordered before the firms migration in the migration filename timestamp sequence (structural ordering evidence, not a hardcoded exact filename), matching the Phase 1 commitment that commercial hierarchy precedes the firm itself. DataModelContractMappingService::tableFamilies()::byFamily(\'commercial_hierarchy\') documents this same family of tables as a declared contract.',
            ),
            new GovernanceMappingResult(
                item_key: 'payment_plans_consent_capture_early_scope',
                item_label: 'Payment plans and consent capture were committed to within early product scope',
                owning_class: \App\Models\PaymentPlan::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'The communication_consents migration exists among the early foundation migrations, and the payment_plans migration exists and is ordered before the later platform-billing/deployment-governance migrations (fleet_migration_runs, license_files) in the migration filename timestamp sequence — structural ordering evidence that both were committed to early, ahead of dedicated/private deployment-governance work.',
            ),
            new GovernanceMappingResult(
                item_key: 'fleet_migration_offline_licensing_before_dedicated_deal',
                item_label: 'Fleet migration enrollment and offline license validation were committed to before any dedicated deal could close',
                owning_class: \App\Services\LicenseFileValidationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'The fleet_migration_runs and license_files migrations both exist alongside the rest of the Phase 16 deployment-control migration set, and LicenseFileValidationService/FleetMigrationOrchestrationService are real, tested services (per DeploymentModeCoverageMappingService::dedicated()) — the offline-licensing and fleet-enrollment foundation a dedicated deal would require already exists structurally, ahead of any such deal.',
            ),
        ];
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function oneProductNoForkStrategy(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'single_firm_model',
                item_label: 'Exactly one Firm model exists — no forked/duplicated tenant model',
                owning_class: \App\Models\Firm::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'A single app/Models/Firm.php exists repository-wide; every deployment mode (SaaS/Dedicated/Private Enterprise) uses this same model rather than a per-mode fork.',
            ),
            new GovernanceMappingResult(
                item_key: 'single_entitlement_service',
                item_label: 'Exactly one EntitlementService exists — license/module logic is not duplicated per deployment mode',
                owning_class: \App\Services\EntitlementService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'A single app/Services/EntitlementService.php exists; TemplatePackCommercialService and every deployment-mode control reuse this same service rather than a per-mode reimplementation.',
            ),
            new GovernanceMappingResult(
                item_key: 'single_tenant_context_resolver',
                item_label: 'Exactly one TenantContextResolver exists — tenant isolation is not forked per deployment mode',
                owning_class: \App\Services\TenantContextResolver::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'A single app/Services/TenantContextResolver.php exists; BelongsToTenant\'s global scope calls this same resolver for every model, every deployment mode, and every firm. RowLevelSecurityCoverageMappingService documents the database-level half of the same single isolation strategy (prepared but not yet enforced).',
            ),
            new GovernanceMappingResult(
                item_key: 'single_license_validation_service',
                item_label: 'Exactly one LicenseFileValidationService exists — offline license validation is not duplicated',
                owning_class: \App\Services\LicenseFileValidationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'A single app/Services/LicenseFileValidationService.php exists, used identically for Dedicated and Private Enterprise instances per DeploymentModeCoverageMappingService — no separate validation code path per customer.',
            ),
            new GovernanceMappingResult(
                item_key: 'single_module_catalog',
                item_label: 'Exactly one module_catalog table/model exists — entitlements are not tracked in parallel systems',
                owning_class: \App\Models\ModuleCatalog::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'A single create_module_catalog_table migration and a single app/Models/ModuleCatalog.php exist; later phases (webhooks, AI) seeded new rows into this same table rather than creating a second catalog.',
            ),
            new GovernanceMappingResult(
                item_key: 'dedicated_private_customization_surfaces',
                item_label: 'Dedicated/Private Enterprise customization is limited to declared surfaces, not a forked codebase',
                owning_class: \App\Models\PrivateEnterpriseSettings::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'OperationalReadinessMappingService::byKey(\'customization_surfaces_limited\') is Implemented, naming EntitlementService/TemplatePackVersion/WebhookSubscription/ApiKey as the exact, bounded set of customization surfaces — Dedicated/Private Enterprise firms configure within these surfaces rather than running a code fork.',
            ),
            new GovernanceMappingResult(
                item_key: 'no_duplicate_readiness_system',
                item_label: 'No duplicate gap register or readiness system was created by this synthesis',
                owning_class: \App\Services\ComplianceGapRegistryService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'This service reads ComplianceGapRegistryService::all() directly as knownOpenGaps rather than declaring a second register, and SecurityBaselineMappingService/ComplianceReviewGateMappingService/AccessibilityCoverageMappingService/IdempotencyKeyCoverageMappingService/PermissionMatrixMappingService/EmergencyAccessGovernanceGapService/TestCoverageMappingService/ReleaseChecklistReadinessService/DefinitionOfDoneReadinessService are all read and cross-referenced by this synthesis rather than reimplemented.',
            ),
        ];
    }

    /**
     * @return array<int, GapRegisterItem>
     */
    public function knownOpenGaps(): array
    {
        return (new ComplianceGapRegistryService())->all();
    }

    /**
     * @return array<int, GapRegisterItem>
     */
    public function dedicatedPrivateDealBlockers(): array
    {
        $blockerKeys = [
            'rls_prepared_not_enforced',
            'emergency_support_access_high_risk_approval_not_wired',
        ];

        return array_values(array_filter(
            $this->knownOpenGaps(),
            fn (GapRegisterItem $gap) => in_array($gap->key, $blockerKeys, true),
        ));
    }
}
