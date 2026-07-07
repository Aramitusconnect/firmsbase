<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * AdminControlCatalogMappingService — declares the master plan's
 * Section 34 admin control catalog (89 controls across 12 admin
 * areas) and maps every control to the real, existing backend
 * service/model/policy evidence found by direct repository
 * inspection, or honestly classifies it NotFound. Purely declarative
 * — no migration, no new enum, no new value object, no admin UI
 * generated. A control being backend-only (no Filament resource/page)
 * is captured in notes/backendOnly(), never automatically treated as
 * a gap.
 *
 * AWS confirmed a Filament admin panel PROVIDER exists
 * (app/Providers/Filament/AdminPanelProvider.php, registered in
 * bootstrap/providers.php, a login-protected panel with
 * discoverResources()/discoverPages() pointed at app/Filament/
 * Resources and app/Filament/Pages) — but app/Filament itself does
 * not exist at all, so zero actual admin resources/pages exist for
 * any of these 89 controls. The panel shell is real; it is simply
 * unused. This service does not create, modify, or discover any
 * Filament resource — it only reports this finding as evidence.
 *
 * Every classification below was determined by direct inspection of
 * the real repository (all relevant app/Services, app/Models, and
 * app/Enums) at the time this service was written.
 */
class AdminControlCatalogMappingService
{
    private const AREAS = [
        'organization_management', 'firm_management', 'plan_license_management',
        'module_entitlements', 'ai_controls', 'payment_controls', 'trust_controls',
        'template_controls', 'deployment_fleet', 'support_controls', 'customer_success',
        'operations',
    ];

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function all(): array
    {
        return array_merge(
            $this->organizationManagement(),
            $this->firmManagement(),
            $this->planLicenseManagement(),
            $this->moduleEntitlements(),
            $this->aiControls(),
            $this->paymentControls(),
            $this->trustControls(),
            $this->templateControls(),
            $this->deploymentFleet(),
            $this->supportControls(),
            $this->customerSuccess(),
            $this->operations(),
        );
    }

    public function byKey(string $key): ?GovernanceMappingResult
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function area(string $area): array
    {
        return array_filter(
            $this->all(),
            fn (string $key) => str_starts_with($key, "{$area}."),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<int, string>
     */
    public function areas(): array
    {
        return self::AREAS;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function implemented(): array
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
    public function notFound(): array
    {
        return $this->byStatus(GovernanceMappingStatus::NotFound);
    }

    /**
     * Controls with a real UI/admin-resource/page. Empty: AWS
     * confirmed app/Filament does not exist at all (only the empty
     * AdminPanelProvider shell), so no control in this catalog has
     * real UI backing today.
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function uiBacked(): array
    {
        return [];
    }

    /**
     * Controls with real backend support but no admin UI yet — every
     * Implemented/PartiallyImplemented control in this catalog,
     * since uiBacked() is empty.
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function backendOnly(): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->status !== GovernanceMappingStatus::NotFound,
        );
    }

    /**
     * Controls that are dangerous to expose in an admin UI before
     * hardening. Must include emergency support access while
     * emergency_support_access_high_risk_approval_not_wired remains
     * an open gap in ComplianceGapRegistryService.
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function dangerousBeforeHardening(): array
    {
        $keys = ['support_controls.approve_emergency_support_access'];

        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function controlCoverage(): array
    {
        $coverage = [];

        foreach (self::AREAS as $area) {
            $controls = $this->area($area);
            $statuses = array_map(fn (GovernanceMappingResult $c) => $c->status, $controls);

            $allImplemented = ! in_array(GovernanceMappingStatus::NotFound, $statuses, true)
                && ! in_array(GovernanceMappingStatus::PartiallyImplemented, $statuses, true);

            $status = $allImplemented ? GovernanceMappingStatus::Implemented : GovernanceMappingStatus::PartiallyImplemented;

            $coverage[$area] = new GovernanceMappingResult(
                item_key: $area,
                item_label: "{$area} area-level coverage",
                owning_class: null,
                status: $status,
                notes: sprintf(
                    '%d/%d controls Implemented for %s. No admin UI exists for any control in this area (backend-only today).',
                    count(array_filter($statuses, fn ($s) => $s === GovernanceMappingStatus::Implemented)),
                    count($statuses),
                    $area,
                ),
            );
        }

        return $coverage;
    }

    /**
     * Gap candidates from this section — usually only the form-edition
     * watch SLA finding.
     *
     * @return array<int, GovernanceMappingResult>
     */
    public function gaps(): array
    {
        $entry = $this->byKey('template_controls.manage_form_edition_slas');

        return $entry && $entry->status === GovernanceMappingStatus::NotFound ? [$entry] : [];
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
     * @return array<string, GovernanceMappingResult>
     */
    private function organizationManagement(): array
    {
        return $this->build('organization_management', [
            'create_organizations' => [\App\Models\Organization::class, GovernanceMappingStatus::Implemented, 'Organization is a real model; the tenancy root, present since Phase 1.'],
            'create_billing_accounts' => [\App\Models\BillingAccount::class, GovernanceMappingStatus::Implemented, 'BillingAccount is a real model, linked to Organization via organization_id.'],
            'attach_detach_firms' => [\App\Models\Firm::class, GovernanceMappingStatus::Implemented, 'Firm.organization_id/billing_account_id are real, nullable FKs — a firm can be attached/detached by reassigning them.'],
            'assign_org_licenses' => [\App\Services\OrgLicenseService::class, GovernanceMappingStatus::Implemented, 'OrgLicenseService creates real org_licenses rows (organization_id/plan_id/billing_account_id/license_status/starts_at/renews_at/expires_at).'],
            'configure_seat_pools' => [\App\Models\SeatPool::class, GovernanceMappingStatus::Implemented, 'SeatPool is a real, organization-owned model (seat_class/total_seats/allocated_seats/counting_mode/period).'],
            'allocate_seats' => [\App\Models\SeatAllocation::class, GovernanceMappingStatus::Implemented, 'SeatAllocation is a real model (firm_id/seat_pool_id/seat_class/seats_allocated/status), the per-firm draw against a SeatPool.'],
            'set_conflict_scope_posture' => [\App\Models\Organization::class, GovernanceMappingStatus::Implemented, 'Organization.conflict_scope is a real column cast to the real ConflictScope enum, consulted by ConflictCheckService.'],
            'view_consolidated_invoices' => [\App\Models\PlatformInvoice::class, GovernanceMappingStatus::Implemented, 'platform_invoices is keyed to billing_account_id — a single billing account consolidates invoices across every firm attached to it.'],
            'view_usage_attribution' => [\App\Models\UsageRollup::class, GovernanceMappingStatus::Implemented, 'usage_rollups is keyed to billing_account_id with an optional per-firm firm_id column, a real structured attribution mechanism (Section 32 evidence).'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function firmManagement(): array
    {
        return $this->build('firm_management', [
            'create_firm' => [\App\Models\Firm::class, GovernanceMappingStatus::Implemented, 'Firm is the real operating-tenant model.'],
            'edit_firm_settings' => [\App\Models\FirmSettings::class, GovernanceMappingStatus::Implemented, 'FirmSettings is a real, one-per-firm model covering payment_mode/trust/ai_mode/2fa/jurisdiction/branding/security settings.'],
            'set_customer_type' => [\App\Models\Firm::class, GovernanceMappingStatus::Implemented, 'Firm.customer_type is a real column cast to the real CustomerType enum.'],
            'set_deployment_mode' => [\App\Models\Firm::class, GovernanceMappingStatus::Implemented, 'Firm.deployment_mode is a real column cast to the real DeploymentMode enum.'],
            'set_jurisdiction' => [\App\Models\Firm::class, GovernanceMappingStatus::Implemented, 'Firm.primary_country/primary_state and FirmSettings.state_jurisdiction are real columns.'],
            'assign_implementation_owner' => [\App\Models\ImplementationProject::class, GovernanceMappingStatus::Implemented, 'ImplementationProject.assigned_to is a real column, alongside started_at/go_live_at/completed_at/success_review_due_at.'],
            'activate_deactivate_firm' => [\App\Services\FirmProductionActivationService::class, GovernanceMappingStatus::PartiallyImplemented, 'Firm.activation_status supports only draft/onboarding/activated (no explicit "deactivated" value) — activation is real and enforced via FirmProductionActivationService, but deactivating an already-active firm is represented one layer down instead, via firm_licenses.license_status (Suspended/Cancelled/Expired), not a direct Firm-level deactivation state.'],
            'view_health_score' => [\App\Services\CustomerSuccessHealthScoreService::class, GovernanceMappingStatus::Implemented, 'CustomerSuccessHealthScoreService::compute() is real, covering onboarding progress, risk flags, and failed-jobs count.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function planLicenseManagement(): array
    {
        return $this->build('plan_license_management', [
            'create_plans' => [\App\Models\Plan::class, GovernanceMappingStatus::Implemented, 'Plan is a real model (name/status/price_cents/billing_interval/support_access_level/trial_days/is_active).'],
            'edit_prices' => [\App\Models\Plan::class, GovernanceMappingStatus::Implemented, 'Plan.price_cents and billing_interval are real, directly editable columns.'],
            'set_seat_classes_limits' => [\App\Models\PlanLimit::class, GovernanceMappingStatus::Implemented, 'PlanLimit is a real model; SeatClass is a real enum consulted by SeatPool.seat_class.'],
            'assign_licenses' => [\App\Models\FirmLicense::class, GovernanceMappingStatus::Implemented, 'FirmLicense/OrgLicense are real models covering firm- and organization-level license assignment.'],
            'suspend_reactivate_licenses' => [\App\Services\LegalDataAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'LicenseStatus::Suspended is real and consulted by LegalDataAccessPolicyService; FirmLicenseCommercialService provides the status-change writer, called by LicenseFileValidationService for grace/restricted transitions.'],
            'configure_trials' => [\App\Services\OrgLicenseService::class, GovernanceMappingStatus::Implemented, 'LicenseStatus::Trial is real; OrgLicenseService sets it at creation, and Plan.trial_days/trial_requires_card are real columns.'],
            'approve_custom_overrides' => [\App\Services\HighRiskPlatformChangePolicyService::class, GovernanceMappingStatus::PartiallyImplemented, 'No single dedicated "custom license override" approval type exists in HighRiskChangeType, but the general two-person HighRiskPlatformChangePolicyService (request/firstApprove/secondApprove/deny, with a required reason) is real and reusable for override-shaped decisions — DedicatedLegalSpecialistApproval is the one existing concrete override case wired through it.'],
            'issue_signed_license_files' => [\App\Services\LicenseFileSigningService::class, GovernanceMappingStatus::Implemented, 'LicenseFileSigningService performs real Ed25519/sodium signing of license_files rows.'],
            'view_license_history' => [\App\Models\LicenseValidationEvent::class, GovernanceMappingStatus::Implemented, 'LicenseValidationEvent and LicenseEvent are real, append-only history models.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function moduleEntitlements(): array
    {
        return $this->build('module_entitlements', [
            'enable_disable_modules_by_plan' => [\App\Models\PlanModule::class, GovernanceMappingStatus::Implemented, 'PlanModule is a real model linking a Plan to enabled module_catalog rows.'],
            'enable_disable_modules_by_organization' => [\App\Enums\EntitlementSource::class, GovernanceMappingStatus::Implemented, 'EntitlementSource::OrgInherited is a real, precedence-ranked source (2nd of 4, behind AdminOverride/FirmOverride) that EntitlementService::setForSource() can write directly.'],
            'enable_disable_modules_by_firm' => [\App\Models\FirmEntitlement::class, GovernanceMappingStatus::Implemented, 'FirmEntitlement.enabled is a real, per-firm, per-module-code boolean.'],
            'set_entitlement_start_end_dates' => [\App\Models\FirmEntitlement::class, GovernanceMappingStatus::Implemented, 'firm_entitlements.starts_at/ends_at are real, nullable timestamp columns.'],
            'require_admin_approval' => [\App\Models\ModuleCatalog::class, GovernanceMappingStatus::Implemented, 'module_catalog.requires_admin_approval is a real boolean column.'],
            'enforce_backend_access' => [\App\Services\EntitlementService::class, GovernanceMappingStatus::Implemented, 'EntitlementService::resolve()/isEnabled() are real, genuinely-consulted gates (e.g. TemplatePackCommercialService::installIfEntitled()), not decorative.'],
            'log_override_reasons_sources' => [\App\Models\FirmEntitlementEvent::class, GovernanceMappingStatus::Implemented, 'EntitlementService::setForSource() writes a real FirmEntitlementEvent row every time, carrying reason/source/action/actor_type/actor_id/metadata.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function aiControls(): array
    {
        return $this->build('ai_controls', [
            'enable_disable_ai' => [\App\Enums\AiMode::class, GovernanceMappingStatus::Implemented, 'AiMode::Disabled is real and enforced — every AI entry point must block when set.'],
            'choose_platform_managed_or_firm_owned_ai' => [\App\Enums\AiMode::class, GovernanceMappingStatus::Implemented, 'AiMode::PlatformManaged/FirmOwned are both real, enforced modes.'],
            'set_provider_model_allowlist' => [\App\Models\FirmAiSettings::class, GovernanceMappingStatus::Implemented, 'firm_ai_settings.allowed_providers_json/allowed_models_json are real JSON-cast array columns.'],
            'set_token_budget_limits_firm' => [\App\Services\AiBudgetEnforcementService::class, GovernanceMappingStatus::Implemented, 'firm_ai_settings.token_limit_per_period/budget_limit_cents_per_period are real columns, genuinely enforced by AiBudgetEnforcementService.'],
            'set_token_budget_limits_organization' => [\App\Services\AiBudgetEnforcementService::class, GovernanceMappingStatus::PartiallyImplemented, 'AiBudgetEnforcementService\'s own docblock states organization-level budget reuses UsageRollupService/BillingAccount usage tracking rather than a dedicated, directly-settable organization-level LIMIT field — real usage visibility exists, but a distinct org-level budget ceiling control does not.'],
            'require_ai_approvals' => [\App\Services\AiApprovalWorkflowService::class, GovernanceMappingStatus::Implemented, 'firm_ai_settings.high_risk_requires_approval (default true) gates into AiApprovalWorkflowService::submit()/approve()/reject(), restricted to named approval roles.'],
            'disable_document_client_context' => [\App\Models\FirmAiSettings::class, GovernanceMappingStatus::Implemented, 'firm_ai_settings.document_context_enabled/client_data_context_enabled are real booleans, both defaulting to false.'],
            'review_retrieval_isolation_status' => [\App\Services\AiRetrievalIsolationService::class, GovernanceMappingStatus::Implemented, 'AiRetrievalIsolationService is a real, dedicated service for this exact purpose.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function paymentControls(): array
    {
        return $this->build('payment_controls', [
            'enable_disable_payments' => [\App\Services\EntitlementService::class, GovernanceMappingStatus::Implemented, 'The real "payments" module_code (seeded into module_catalog) is gated per firm via firm_entitlements/EntitlementService, exactly like every other module.'],
            'enable_disable_payment_plans' => [\App\Services\EntitlementService::class, GovernanceMappingStatus::Implemented, 'The real "payment_plans" module_code is gated the same way as "payments".'],
            'approve_stripe_setup' => [\App\Enums\HighRiskChangeType::class, GovernanceMappingStatus::PartiallyImplemented, 'HighRiskChangeType::PaymentTrustSettingChange is declared but not wired to any service (confirmed by direct search — the case appears only in its own enum file). No dedicated "approve Stripe setup" workflow exists; enablement today is the same generic entitlement mechanism as any other module, with no Stripe-specific approval step.'],
            'configure_operating_only_mode' => [\App\Services\TrustIoltaDisableAcknowledgmentService::class, GovernanceMappingStatus::Implemented, 'TrustIoltaDisableAcknowledgmentService wires HighRiskChangeType::OperatingOnlyTrustDisableAcknowledgment through the real two-person HighRiskPlatformChangePolicyService (requestApproval/firstApprove/secondApprove) and records the firm\'s acknowledgment.'],
            'block_trust_deposits' => [\App\Services\PaymentClassificationService::class, GovernanceMappingStatus::Implemented, 'PaymentClassificationService unconditionally blocks any requested trust_iolta_payment classification, regardless of firm_settings.payment_mode, per project rule.'],
            'review_payment_classification_events' => [\App\Models\PaymentClassificationEvent::class, GovernanceMappingStatus::Implemented, 'PaymentClassificationEvent is a real, append-only event model recorded by PaymentClassificationService::recordDecision().'],
            'handle_failed_webhooks' => [\App\Services\WebhookRetryPolicyService::class, GovernanceMappingStatus::Implemented, 'WebhookRetryPolicyService and WebhookDeliveryAttemptService are real, dedicated services for retry/failure handling.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function trustControls(): array
    {
        return $this->build('trust_controls', [
            'approve_trust_mode_activation' => [\App\Services\TrustModeActivationService::class, GovernanceMappingStatus::Implemented, 'TrustModeActivationService wires HighRiskChangeType::TrustModeActivation through the real two-person HighRiskPlatformChangePolicyService.'],
            'configure_trust_accounts' => [\App\Models\TrustLedger::class, GovernanceMappingStatus::Implemented, 'TrustLedger is the real trust-account model (firm_id/trust_account_id/client_id/status).'],
            'review_reconciliations' => [\App\Services\TrustReconciliationService::class, GovernanceMappingStatus::Implemented, 'TrustReconciliationService is a real, dedicated reconciliation service.'],
            'approve_high_risk_trust_actions' => [\App\Services\TrustHighRiskAdjustmentService::class, GovernanceMappingStatus::Implemented, 'TrustHighRiskAdjustmentService requires two distinct approvers via TrustAccessPolicyService::assertDistinctApprovers().'],
            'enforce_jurisdiction_controls' => [\App\Services\TrustJurisdictionReadinessService::class, GovernanceMappingStatus::PartiallyImplemented, 'TrustJurisdictionReadinessService::checklistFor() is real, structured reporting, but — matching TrustPilotExitCriteriaService\'s own documented pattern — it is a reporting aid, not an automatic enforcement gate.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function templateControls(): array
    {
        $result = $this->build('template_controls', [
            'create_template_packs' => [\App\Models\TemplatePack::class, GovernanceMappingStatus::Implemented, 'TemplatePack is a real, global catalog model.'],
            'version_template_packs' => [\App\Models\TemplatePackVersion::class, GovernanceMappingStatus::Implemented, 'TemplatePackVersion is a real model, versioned per TemplatePack.'],
            'publish_unpublish_template_packs' => [\App\Enums\TemplatePackStatus::class, GovernanceMappingStatus::Implemented, 'TemplatePackStatus::Draft/Published are real, enforced states on TemplatePackVersion.'],
            'install_template_packs_for_firms' => [\App\Services\TemplatePackCommercialService::class, GovernanceMappingStatus::Implemented, 'TemplatePackCommercialService::installIfEntitled() wraps the real TemplatePackInstallationService.'],
            'preview_template_upgrades' => [\App\Services\TemplateUpgradePreviewService::class, GovernanceMappingStatus::Implemented, 'TemplateUpgradePreviewService is a real, dedicated preview service.'],
            'manage_form_edition_watch_queue' => [\App\Services\FormEditionWatchService::class, GovernanceMappingStatus::Implemented, 'FormEditionWatchService provides a real, full lifecycle: startWatching()/markNewEditionDetected()/markInReview()/markUpdated()/markNoActionNeeded().'],
            'audit_template_changes' => [\App\Services\TimelineEventRecorder::class, GovernanceMappingStatus::PartiallyImplemented, 'No template-specific audit event table exists; the generic TimelineEventRecorder/timeline_events primitive is available and reusable, but no confirmed call site records template pack/version changes through it today.'],
        ], GovernanceMappingStatus::Implemented);

        $result['template_controls.manage_form_edition_slas'] = $this->result(
            'template_controls',
            'manage_form_edition_slas',
            \App\Models\FormEditionWatchItem::class,
            GovernanceMappingStatus::NotFound,
            'form_edition_watch_items has no sla_due_at, no SLA status, no SLA policy, and no escalation column (confirmed by direct migration/model inspection — Section 32 evidence). FormEditionWatchService\'s full method set (startWatching/markNewEditionDetected/markInReview/markUpdated/markNoActionNeeded) never computes or references a due date, deadline, or escalation trigger. No equivalent SLA representation exists anywhere in the repository.',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function deploymentFleet(): array
    {
        return $this->build('deployment_fleet', [
            'view_fleet_version_skew' => [\App\Services\VersionSkewPolicyService::class, GovernanceMappingStatus::Implemented, 'VersionSkewPolicyService::check() is real and enforced.'],
            'plan_fleet_migrations' => [\App\Services\FleetMigrationOrchestrationService::class, GovernanceMappingStatus::Implemented, 'FleetMigrationOrchestrationService::createRun() enrolls every current dedicated/private firm as Pending.'],
            'execute_fleet_migrations' => [\App\Services\FleetMigrationOrchestrationService::class, GovernanceMappingStatus::Implemented, '::begin()/::applyInstance() are real execution-simulation methods.'],
            'halt_fleet_migrations' => [\App\Services\FleetMigrationOrchestrationService::class, GovernanceMappingStatus::Implemented, 'A failed instance automatically halts the run (Section 33 evidence); no separate manual halt control was found beyond this automatic behavior.'],
            'roll_back_fleet_migrations' => [\App\Services\FleetMigrationOrchestrationService::class, GovernanceMappingStatus::Implemented, '::rollback() is real, restricted to Halted/Completed runs.'],
            'review_degradation_mode_status' => [\App\Services\IntegrationDegradationRegistryService::class, GovernanceMappingStatus::Implemented, 'IntegrationDegradationRegistryService is real and queryable (scoped only to IntegrationType, per the existing tracked gap).'],
            'review_health_envelope_reports' => [\App\Services\DeploymentHealthEnvelopeService::class, GovernanceMappingStatus::Implemented, 'DeploymentHealthEnvelopeService::buildEnvelope() is real and produces a queryable health report per firm.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function supportControls(): array
    {
        $result = $this->build('support_controls', [
            'request_firm_approved_access' => [\App\Services\SupportAccessRequestService::class, GovernanceMappingStatus::Implemented, 'SupportAccessRequestService::request()/approve()/deny() is a real, firm-approved access flow.'],
            'set_support_access_time_limit' => [\App\Models\SupportAccessRequest::class, GovernanceMappingStatus::Implemented, 'support_access_requests.requested_duration_minutes is a real column.'],
            'require_support_access_reason' => [\App\Models\SupportAccessRequest::class, GovernanceMappingStatus::Implemented, 'support_access_requests.reason is a real, populated column.'],
            'notify_firm_of_support_access' => [\App\Services\SupportAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'SupportAccessPolicyService::logNotification() is real.'],
            'audit_support_actions' => [\App\Services\SupportAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'SupportAccessPolicyService::logSessionAudit() is real.'],
        ], GovernanceMappingStatus::Implemented);

        $result['support_controls.approve_emergency_support_access'] = $this->result(
            'support_controls',
            'approve_emergency_support_access',
            \App\Services\EmergencyAccessGovernanceGapService::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'support_access_requests.emergency_justification is real, but SupportAccessPolicyService/SupportAccessRequestService never call HighRiskPlatformChangePolicyService for HighRiskChangeType::EmergencySupportAccess — the existing, tracked emergency_support_access_high_risk_approval_not_wired gap (see EmergencyAccessGovernanceGapService and ComplianceGapRegistryService) applies here directly and is referenced, not duplicated.',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function customerSuccess(): array
    {
        return $this->build('customer_success', [
            'view_onboarding_progress' => [\App\Services\CustomerSuccessHealthScoreService::class, GovernanceMappingStatus::Implemented, 'CustomerSuccessHealthScoreService::onboardingProgressPercent() is real.'],
            'view_risk_flags' => [\App\Services\CustomerSuccessHealthScoreService::class, GovernanceMappingStatus::Implemented, 'CustomerSuccessHealthScoreService::scoreAndRiskFlags() is real.'],
            'view_usage_analytics' => [\App\Models\ProductAnalyticsEvent::class, GovernanceMappingStatus::Implemented, 'ProductAnalyticsEvent/UsageRollup are real, queryable usage data sources.'],
            'view_failed_jobs' => [\App\Services\CustomerSuccessHealthScoreService::class, GovernanceMappingStatus::Implemented, 'failedJobsCount is a real input to CustomerSuccessHealthScoreService::scoreAndRiskFlags().'],
            'view_document_chase_performance' => [\App\Models\DocumentChaseEvent::class, GovernanceMappingStatus::PartiallyImplemented, 'DocumentChaseEvent (event_type reminder_queued/reminder_skipped/escalated) is real, queryable raw data, but no dedicated "chase performance" aggregation/reporting service was found.'],
            'view_payment_plan_collection_performance' => [\App\Services\FirmCommandCenterAggregationService::class, GovernanceMappingStatus::PartiallyImplemented, 'FirmCommandCenterAggregationService surfaces installmentsDueCount/installmentsMissedCount from real data, but no dedicated "collection performance" trend/rate metric service was found.'],
            'view_trial_conversion_progress' => [\App\Services\ConversionEventService::class, GovernanceMappingStatus::Implemented, 'ConversionEventService records a real ConversionEventType::TrialToPaid event.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function operations(): array
    {
        return $this->build('operations', [
            'manage_announcements' => [\App\Services\AnnouncementService::class, GovernanceMappingStatus::Implemented, 'AnnouncementService is a real, dedicated service.'],
            'manage_release_notes' => [\App\Services\ReleaseNoteService::class, GovernanceMappingStatus::Implemented, 'ReleaseNoteService is a real, dedicated service.'],
            'manage_status_incidents' => [\App\Services\StatusPageService::class, GovernanceMappingStatus::Implemented, 'StatusPageService and IncidentService are both real, dedicated services.'],
            'manage_vendor_register' => [\App\Services\VendorRegisterService::class, GovernanceMappingStatus::Implemented, 'VendorRegisterService is a real, dedicated service.'],
            'manage_access_reviews' => [\App\Services\AccessReviewService::class, GovernanceMappingStatus::Implemented, 'AccessReviewService::initiate()/enumerateItems()/recordDecision()/summary()/complete() is a real, full lifecycle.'],
            'manage_retention_policies' => [\App\Services\RetentionPolicyService::class, GovernanceMappingStatus::Implemented, 'RetentionPolicyService::resolveEffectivePolicyFor() is real.'],
            'manage_offboarding_requests' => [\App\Services\OffboardingRequestService::class, GovernanceMappingStatus::Implemented, 'OffboardingRequestService::request()/evaluateReadiness()/advance()/complete()/cancel() is a real, full lifecycle, each requiring a reason.'],
            'manage_key_destruction_requests' => [\App\Services\KeyDestructionApprovalService::class, GovernanceMappingStatus::Implemented, 'KeyDestructionApprovalService wires HighRiskChangeType::CryptographicKeyDestruction through a real two-person approval, each step requiring a reason.'],
        ]);
    }

    /**
     * @param  array<string, array{0: ?string, 1: GovernanceMappingStatus, 2: string}>  $controls
     * @return array<string, GovernanceMappingResult>
     */
    private function build(string $area, array $controls, ?GovernanceMappingStatus $defaultStatus = null): array
    {
        $result = [];

        foreach ($controls as $control => [$owningClass, $status, $notes]) {
            $result["{$area}.{$control}"] = $this->result($area, $control, $owningClass, $status, $notes);
        }

        return $result;
    }

    private function result(string $area, string $control, ?string $owningClass, GovernanceMappingStatus $status, string $notes): GovernanceMappingResult
    {
        return new GovernanceMappingResult(
            item_key: "{$area}.{$control}",
            item_label: "{$area}.{$control}",
            owning_class: $owningClass,
            status: $status,
            notes: $notes,
        );
    }
}
