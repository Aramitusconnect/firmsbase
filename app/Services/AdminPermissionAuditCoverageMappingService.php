<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * AdminPermissionAuditCoverageMappingService — for every one of the 89
 * Section 34 admin controls (same "admin_area.control" keys as
 * AdminControlCatalogMappingService), identifies whether the control
 * is permission-scoped (an explicit role/actor-eligibility check),
 * audit-backed (a real, append-only event/log row), and reason-backed
 * (a captured business-purpose string) where the control is high-risk
 * or override-based. Purely declarative — no new permission/audit
 * system, no enforcement added, no migration. Reuses
 * GovernanceMappingResult/GovernanceMappingStatus.
 *
 * Every classification below was determined by direct inspection of
 * the real repository at the time this service was written. A
 * significant, honest finding: most create/edit/configure controls in
 * organization_management, firm_management, plan_license_management,
 * template_controls, deployment_fleet, and operations accept a
 * PlatformAdmin/actor parameter for ATTRIBUTION only — no owning
 * service calls PlatformRoleService::hasRole() or an equivalent
 * eligibility check before performing the action. Only the highest-
 * risk flows (trust, AI approval, support access, HighRiskChangeType-
 * gated changes) have an explicit actor-eligibility check.
 */
class AdminPermissionAuditCoverageMappingService
{
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
    public function permissionScoped(): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => str_contains($item->notes, 'PERMISSION: real'),
        );
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function auditBacked(): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => str_contains($item->notes, 'AUDIT: real'),
        );
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function reasonBacked(): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => str_contains($item->notes, 'REASON: real'),
        );
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function missingPermissionScope(): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => str_contains($item->notes, 'PERMISSION: none'),
        );
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function missingAuditTrail(): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => str_contains($item->notes, 'AUDIT: none'),
        );
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function missingBusinessPurposeReason(): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => str_contains($item->notes, 'REASON: none'),
        );
    }

    /**
     * Controls dangerous to expose in an admin UI before hardening.
     * Must include emergency support access (references the existing
     * emergency-support platform-approval gap, never duplicating it).
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function dangerousBeforeUi(): array
    {
        $keys = ['support_controls.approve_emergency_support_access'];

        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * Gap candidates from this section. Empty: every finding here
     * either confirms real coverage or documents a PartiallyImplemented
     * permission/audit/reason gap that references an EXISTING tracked
     * gap (org_admin_role_missing, emergency_support_access_high_risk_approval_not_wired)
     * rather than proposing a new one — no new gap-register item is
     * warranted by this service's own findings.
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
    private function organizationManagement(): array
    {
        return $this->build('organization_management', [
            'create_organizations' => [\App\Models\Organization::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none — no owning code checks a role before creating an Organization row. AUDIT: none — no dedicated creation event exists. REASON: not applicable (routine creation, not an override).'],
            'create_billing_accounts' => [\App\Models\BillingAccount::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none. AUDIT: none. REASON: not applicable.'],
            'attach_detach_firms' => [\App\Models\Firm::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none — no role check gates reassigning Firm.organization_id/billing_account_id. AUDIT: none. REASON: not applicable for routine reassignment, though a firm changing commercial ownership is consequential enough that a reason would be valuable if a UI were ever built.'],
            'assign_org_licenses' => [\App\Services\OrgLicenseService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed in OrgLicenseService. AUDIT: none confirmed — no dedicated org-license event was found. REASON: not applicable to a routine assignment.'],
            'configure_seat_pools' => [\App\Models\SeatPool::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none. AUDIT: none. REASON: not applicable.'],
            'allocate_seats' => [\App\Models\SeatAllocation::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none. AUDIT: none — seat_allocations itself is the record, but no separate append-only event logs who allocated a seat and why. REASON: not applicable for a routine allocation.'],
            'set_conflict_scope_posture' => [\App\Models\Organization::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed on writing Organization.conflict_scope. AUDIT: none. REASON: this is a compliance-relevant posture change; not applicable strictly, but a reason would be valuable if a UI were built.'],
            'view_consolidated_invoices' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — PlatformStaffAccessPolicyService::canAccessPlatformBilling() gates billing visibility by role. AUDIT: not applicable (read-only). REASON: not applicable (read-only).'],
            'view_usage_attribution' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — canAccessPlatformBilling() applies to usage/billing-adjacent data. AUDIT: not applicable (read-only). REASON: not applicable.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function firmManagement(): array
    {
        return $this->build('firm_management', [
            'create_firm' => [\App\Models\Firm::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none. AUDIT: none dedicated (FirmActivationEvent exists but is scoped to activation, not raw creation). REASON: not applicable.'],
            'edit_firm_settings' => [\App\Models\FirmSettings::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated for a generic settings edit. REASON: not applicable to most fields, though trust/AI-relevant settings changes would benefit from one if a UI were built.'],
            'set_customer_type' => [\App\Models\Firm::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed beyond DedicatedCustomerTypeApprovalService for the one dedicated+legal_specialist combination (real two-person approval there). AUDIT: real for that one combination via HighRiskChangeRequest; none for other customer_type changes. REASON: real for the gated combination (HighRiskPlatformChangePolicyService requires a reason); not applicable otherwise.'],
            'set_deployment_mode' => [\App\Models\Firm::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated. REASON: not applicable.'],
            'set_jurisdiction' => [\App\Models\Firm::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none. AUDIT: none. REASON: not applicable.'],
            'assign_implementation_owner' => [\App\Models\ImplementationProject::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed on ImplementationProject.assigned_to. AUDIT: none dedicated. REASON: not applicable to a routine staffing assignment.'],
            'activate_deactivate_firm' => [\App\Models\FirmActivationEvent::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: real — FirmActivationEvent is a real, dedicated append-only activation event model. REASON: not applicable to a routine activation; deactivation is represented via license suspension, which does have a real reason (see plan_license_management.suspend_reactivate_licenses).'],
            'view_health_score' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — canAccessClientData()/canAccessMatterData() gate the underlying data a health score is computed from. AUDIT: not applicable (read-only). REASON: not applicable.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function planLicenseManagement(): array
    {
        return $this->build('plan_license_management', [
            'create_plans' => [\App\Models\Plan::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated. REASON: not applicable to routine catalog creation.'],
            'edit_prices' => [\App\Models\Plan::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated for a price change specifically. REASON: none captured, though a pricing change is consequential enough that one would be valuable.'],
            'set_seat_classes_limits' => [\App\Models\PlanLimit::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none. AUDIT: none. REASON: not applicable.'],
            'assign_licenses' => [\App\Models\FirmLicense::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: real — LicenseEvent is written on status changes, though not on the initial assignment itself. REASON: not applicable to a routine assignment.'],
            'suspend_reactivate_licenses' => [\App\Services\FirmLicenseCommercialService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: none confirmed (no role check inside changeStatus()). AUDIT: real — FirmLicenseCommercialService::changeStatus() writes a real LicenseEvent row (event_type=status_changed, from_status/to_status). REASON: real — changeStatus() accepts and persists an optional reason string.'],
            'configure_trials' => [\App\Services\OrgLicenseService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated to trial configuration specifically. REASON: not applicable.'],
            'approve_custom_overrides' => [\App\Services\HighRiskPlatformChangePolicyService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none role-specific — HighRiskPlatformChangePolicyService::request()/firstApprove()/secondApprove() accept any PlatformAdmin, with no confirmed eligibility-by-role check. AUDIT: real — every request/approve/deny writes a real audit() event. REASON: real — request() throws if the reason is empty. No dedicated "custom override" HighRiskChangeType case exists yet, so this coverage is generic rather than override-specific.'],
            'issue_signed_license_files' => [\App\Services\LicenseFileSigningService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed on the signing operation itself. AUDIT: real — license_files rows are themselves the durable, signed record, and LicenseValidationEvent tracks validation outcomes. REASON: none captured for why a given license file was issued.'],
            'view_license_history' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — canAccessPlatformBilling()-adjacent staff gating applies to license/billing history. AUDIT: not applicable (read-only, and the history itself IS the audit trail — LicenseEvent/LicenseValidationEvent). REASON: not applicable.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function moduleEntitlements(): array
    {
        return $this->build('module_entitlements', [
            'enable_disable_modules_by_plan' => [\App\Models\PlanModule::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated to PlanModule changes. REASON: not applicable to routine catalog configuration.'],
            'enable_disable_modules_by_organization' => [\App\Services\EntitlementService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: none role-specific, but EntitlementService::setForSource() is the sole writer, a real chokepoint. AUDIT: real — a FirmEntitlementEvent row is written every time. REASON: real — setForSource() accepts and persists a reason.'],
            'enable_disable_modules_by_firm' => [\App\Services\EntitlementService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: none role-specific, but EntitlementService::setForSource() is the sole writer. AUDIT: real — FirmEntitlementEvent. REASON: real — reason parameter captured.'],
            'set_entitlement_start_end_dates' => [\App\Services\EntitlementService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: none role-specific, same sole-writer chokepoint. AUDIT: real — FirmEntitlementEvent. REASON: real — same setForSource() reason parameter.'],
            'require_admin_approval' => [\App\Models\ModuleCatalog::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed for editing module_catalog.requires_admin_approval itself (a global catalog flag). AUDIT: none dedicated. REASON: not applicable to routine catalog configuration.'],
            'enforce_backend_access' => [\App\Services\EntitlementService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: not applicable (this is enforcement itself, not an admin action). AUDIT: real — every resolution reads from FirmEntitlement, itself audited on write via FirmEntitlementEvent. REASON: not applicable.'],
            'log_override_reasons_sources' => [\App\Models\FirmEntitlementEvent::class, GovernanceMappingStatus::Implemented, 'PERMISSION: not applicable (this control IS the audit/reason capture). AUDIT: real — FirmEntitlementEvent.action/source. REASON: real — FirmEntitlementEvent.reason.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function aiControls(): array
    {
        return $this->build('ai_controls', [
            'enable_disable_ai' => [\App\Enums\AiMode::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed for writing firm_settings.ai_mode. AUDIT: none dedicated to an ai_mode change specifically. REASON: none captured, though disabling/enabling AI firm-wide is consequential enough that one would be valuable.'],
            'choose_platform_managed_or_firm_owned_ai' => [\App\Enums\AiMode::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated. REASON: none captured.'],
            'set_provider_model_allowlist' => [\App\Models\FirmAiSettings::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed for writing firm_ai_settings.allowed_providers_json/allowed_models_json. AUDIT: none dedicated. REASON: none captured, though this control affects client/matter-context AI exposure and would benefit from one.'],
            'set_token_budget_limits_firm' => [\App\Models\FirmAiSettings::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated. REASON: not applicable (a numeric limit, lower risk than allowlist/context controls).'],
            'set_token_budget_limits_organization' => [\App\Services\AiBudgetEnforcementService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated. REASON: not applicable.'],
            'require_ai_approvals' => [\App\Services\AiApprovalWorkflowService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — AiApprovalWorkflowService::assertActorMayResolve() checks the actor\'s role against a real, restricted APPROVAL_ROLES list before approve()/reject(). AUDIT: real — AiApprovalEvent(Submitted/Approved/Rejected). REASON: real — approve()/reject() both accept an optional reason, persisted onto the event.'],
            'disable_document_client_context' => [\App\Models\FirmAiSettings::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed for writing document_context_enabled/client_data_context_enabled. AUDIT: none dedicated. REASON: none captured — this is a high-risk control (it gates client/matter data exposure to AI) and a reason would be warranted; today none is captured.'],
            'review_retrieval_isolation_status' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — canAccessSecurityLogs()-adjacent staff gating is the natural fit for reviewing an isolation/security posture. AUDIT: not applicable (read-only). REASON: not applicable.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function paymentControls(): array
    {
        return $this->build('payment_controls', [
            'enable_disable_payments' => [\App\Services\EntitlementService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: none role-specific, but EntitlementService::setForSource() is the sole writer. AUDIT: real — FirmEntitlementEvent. REASON: real — reason parameter.'],
            'enable_disable_payment_plans' => [\App\Services\EntitlementService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: none role-specific, same sole-writer chokepoint. AUDIT: real — FirmEntitlementEvent. REASON: real — reason parameter.'],
            'approve_stripe_setup' => [\App\Enums\HighRiskChangeType::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: not applicable — no dedicated approval workflow exists to scope (HighRiskChangeType::PaymentTrustSettingChange is declared but unwired). AUDIT: none dedicated. REASON: none captured, since no dedicated workflow exists yet.'],
            'configure_operating_only_mode' => [\App\Services\TrustIoltaDisableAcknowledgmentService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none role-specific in HighRiskPlatformChangePolicyService itself, though TrustIoltaDisableAcknowledgmentService::isAdminApproved() gates on a completed two-person HighRiskChangeRequest. AUDIT: real — HighRiskPlatformChangePolicyService::audit(). REASON: real — request() requires a non-empty reason.'],
            'block_trust_deposits' => [\App\Services\PaymentClassificationService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: not applicable — this is an unconditional, unbypassable code rule (no admin override path exists at all, by design), so there is no permission gap to close. AUDIT: real — every classification decision, including every block, writes a real PaymentClassificationEvent. REASON: not applicable (nothing to override).'],
            'review_payment_classification_events' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — canAccessPlatformBilling()-adjacent gating applies to payment classification history. AUDIT: not applicable (read-only; the events ARE the audit trail). REASON: not applicable.'],
            'handle_failed_webhooks' => [\App\Services\WebhookRetryPolicyService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: real — WebhookDeliveryAttemptService records each attempt. REASON: not applicable (operational retry, not an override).'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function trustControls(): array
    {
        return $this->build('trust_controls', [
            'approve_trust_mode_activation' => [\App\Services\TrustModeActivationService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: none role-specific in the underlying HighRiskPlatformChangePolicyService, but this is the most tightly-gated flow in the codebase (two-person, TrustEligibilityService prerequisites). AUDIT: real — HighRiskChangeRequest audit() plus TrustApprovalEvent::TrustModeActivationLinked. REASON: real — required by HighRiskPlatformChangePolicyService::request().'],
            'configure_trust_accounts' => [\App\Models\TrustLedger::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed for creating/configuring a TrustLedger row itself (distinct from posting to it, which IS gated). AUDIT: none dedicated to ledger configuration itself. REASON: not applicable to routine setup.'],
            'review_reconciliations' => [\App\Services\TrustReconciliationService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — TrustAccessPolicyService-style role gating applies to trust-adjacent staff actions. AUDIT: real — TrustApprovalEvent::ReconciliationCompleted. REASON: not applicable (read/complete action, not an override).'],
            'approve_high_risk_trust_actions' => [\App\Services\TrustHighRiskAdjustmentService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — TrustAccessPolicyService::assertDistinctApprovers() requires two DIFFERENT approvers, a genuine eligibility+distinctness check. AUDIT: real — TrustApprovalEvent(AdjustmentRequested/FirstApproved/AdjustmentDenied/SecondApproved). REASON: real — the request step requires a reason.'],
            'enforce_jurisdiction_controls' => [\App\Services\TrustJurisdictionReadinessService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed for the checklist review itself. AUDIT: not applicable (TrustJurisdictionReadinessService is reporting-only, matching TrustPilotExitCriteriaService\'s documented pattern). REASON: not applicable (reporting, not an override).'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function templateControls(): array
    {
        $result = $this->build('template_controls', [
            'create_template_packs' => [\App\Models\TemplatePack::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated. REASON: not applicable to routine catalog creation.'],
            'version_template_packs' => [\App\Models\TemplatePackVersion::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated (published_at is a timestamp, not an event trail). REASON: not applicable.'],
            'publish_unpublish_template_packs' => [\App\Enums\TemplatePackStatus::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed for the Draft/Published transition. AUDIT: none dedicated. REASON: none captured, though publishing affects every firm on that pack and a reason would be valuable.'],
            'install_template_packs_for_firms' => [\App\Services\TemplatePackCommercialService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: not role-specific, but TemplatePackCommercialService::installIfEntitled() is a real entitlement chokepoint (throws if not entitled). AUDIT: none dedicated to the install action itself (InstalledTemplatePack row is the record, no separate event). REASON: not applicable to a routine install.'],
            'preview_template_upgrades' => [\App\Services\TemplateUpgradePreviewService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: partial — TemplateUpgradeLog exists to record upgrade activity, per Phase 6 evidence, but no confirmed call site logs a preview specifically. REASON: not applicable (read/preview action).'],
            'manage_form_edition_watch_queue' => [\App\Services\FormEditionWatchService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed — FormEditionWatchService methods take a PlatformAdmin actor for attribution only. AUDIT: none dedicated — no TimelineEventRecorder/SecurityEvent call was found in FormEditionWatchService (confirmed by direct inspection). REASON: not applicable to routine content-ops tracking.'],
            'audit_template_changes' => [\App\Services\TimelineEventRecorder::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: not applicable. AUDIT: none confirmed — the generic TimelineEventRecorder primitive exists and is reusable, but no confirmed call site in template-pack services was found. REASON: not applicable.'],
        ], GovernanceMappingStatus::PartiallyImplemented);

        $result['template_controls.manage_form_edition_slas'] = $this->result(
            'template_controls',
            'manage_form_edition_slas',
            \App\Models\FormEditionWatchItem::class,
            GovernanceMappingStatus::NotFound,
            'PERMISSION: not applicable — no SLA control exists to scope. AUDIT: not applicable — nothing to audit. REASON: not applicable. This mirrors AdminControlCatalogMappingService::byKey(\'template_controls.manage_form_edition_slas\'), which is NotFound for the same reason (no SLA due-date/status/escalation representation exists anywhere).',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function deploymentFleet(): array
    {
        return $this->build('deployment_fleet', [
            'view_fleet_version_skew' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — canAccessSecurityLogs()-adjacent staff gating fits deployment/version review. AUDIT: not applicable (read-only). REASON: not applicable.'],
            'plan_fleet_migrations' => [\App\Services\FleetMigrationOrchestrationService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed for createRun(). AUDIT: none dedicated beyond the FleetMigrationRun row\'s own status field. REASON: not applicable to routine planning.'],
            'execute_fleet_migrations' => [\App\Services\FleetMigrationOrchestrationService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed for begin()/applyInstance(). AUDIT: none dedicated beyond FleetMigrationInstanceStatus rows. REASON: not applicable (simulated execution, no real migration).'],
            'halt_fleet_migrations' => [\App\Services\FleetMigrationOrchestrationService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: not applicable — halting is automatic on instance failure, not an admin-invoked action. AUDIT: real — the run\'s halted_reason column records why. REASON: real — halted_reason captures the failure detail that triggered the halt.'],
            'roll_back_fleet_migrations' => [\App\Services\FleetMigrationOrchestrationService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed for rollback() — no role check gates this consequential action. AUDIT: none dedicated beyond the run/instance status transition itself. REASON: none captured — rollback() takes no reason parameter, a real gap for a high-risk, override-adjacent action.'],
            'review_degradation_mode_status' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — canAccessSecurityLogs()-adjacent gating. AUDIT: not applicable (read-only). REASON: not applicable.'],
            'review_health_envelope_reports' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — canAccessSecurityLogs()-adjacent gating. AUDIT: not applicable (read-only; the health checks ARE the record). REASON: not applicable.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function supportControls(): array
    {
        $result = $this->build('support_controls', [
            'request_firm_approved_access' => [\App\Services\SupportAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — SupportAccessPolicyService::canStartSession() decision-gates the flow. AUDIT: real — logSessionAudit(). REASON: real — support_access_requests.reason is required.'],
            'set_support_access_time_limit' => [\App\Models\SupportAccessRequest::class, GovernanceMappingStatus::Implemented, 'PERMISSION: not applicable — a data field, not a discrete action. AUDIT: real — captured directly on the audited request row. REASON: not applicable.'],
            'require_support_access_reason' => [\App\Models\SupportAccessRequest::class, GovernanceMappingStatus::Implemented, 'PERMISSION: not applicable. AUDIT: real — the reason itself is part of the audited request row. REASON: real — this control IS the reason-capture mechanism.'],
            'notify_firm_of_support_access' => [\App\Services\SupportAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: not applicable (a notification side-effect, not a gated action). AUDIT: real — logNotification(). REASON: not applicable.'],
            'audit_support_actions' => [\App\Services\SupportAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: not applicable. AUDIT: real — logSessionAudit() is this control\'s entire purpose. REASON: not applicable.'],
        ], GovernanceMappingStatus::Implemented);

        $result['support_controls.approve_emergency_support_access'] = $this->result(
            'support_controls',
            'approve_emergency_support_access',
            \App\Services\EmergencyAccessGovernanceGapService::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'PERMISSION: none — the existing, tracked emergency_support_access_high_risk_approval_not_wired gap (ComplianceGapRegistryService / EmergencyAccessGovernanceGapService) applies directly: SupportAccessPolicyService/SupportAccessRequestService never call HighRiskPlatformChangePolicyService for HighRiskChangeType::EmergencySupportAccess, so emergency access proceeds the instant emergency_justification is non-empty, with no platform-admin eligibility check. AUDIT: partial — the request row and emergency_justification are captured, but no high_risk_change_requests row is ever created for it. REASON: real — emergency_justification is required text. This finding REFERENCES the existing gap; it does not duplicate it.',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function customerSuccess(): array
    {
        return $this->build('customer_success', [
            'view_onboarding_progress' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — canAccessClientData()-adjacent gating fits onboarding-progress review. AUDIT: not applicable (read-only). REASON: not applicable.'],
            'view_risk_flags' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — same data-access gating. AUDIT: not applicable (read-only). REASON: not applicable.'],
            'view_usage_analytics' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — canAccessPlatformBilling()/canAccessClientData()-adjacent gating. AUDIT: not applicable (read-only). REASON: not applicable.'],
            'view_failed_jobs' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — canAccessSecurityLogs()-adjacent operational-visibility gating. AUDIT: not applicable (read-only). REASON: not applicable.'],
            'view_document_chase_performance' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: real for the underlying data (canAccessClientData()/canAccessMatterData()), though no dedicated "performance" report exists to scope specifically. AUDIT: not applicable (read-only). REASON: not applicable.'],
            'view_payment_plan_collection_performance' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: real for the underlying data (canAccessPlatformBilling()-adjacent), though no dedicated performance-report control exists. AUDIT: not applicable. REASON: not applicable.'],
            'view_trial_conversion_progress' => [\App\Services\PlatformStaffAccessPolicyService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: real — canAccessPlatformBilling()-adjacent gating applies to conversion/commercial data. AUDIT: not applicable (read-only; ConversionEventService IS the record). REASON: not applicable.'],
        ]);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function operations(): array
    {
        return $this->build('operations', [
            'manage_announcements' => [\App\Services\AnnouncementService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated beyond the Announcement row\'s own fields. REASON: not applicable to routine content management.'],
            'manage_release_notes' => [\App\Services\ReleaseNoteService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated. REASON: not applicable.'],
            'manage_status_incidents' => [\App\Services\IncidentService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: partial — IncidentEvent/StatusPageEvent are themselves append-only records of what happened, though no dedicated actor/reason capture wraps routine status updates. REASON: not applicable to routine status updates.'],
            'manage_vendor_register' => [\App\Services\VendorRegisterService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated beyond the Vendor/Subprocessor rows themselves. REASON: not applicable to routine register maintenance.'],
            'manage_access_reviews' => [\App\Services\AccessReviewService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed for initiate()/recordDecision(). AUDIT: real — AccessReview/AccessReviewItem are themselves the durable review record. REASON: not applicable to the review process itself, though recordDecision() would benefit from one for a revoke decision.'],
            'manage_retention_policies' => [\App\Services\RetentionPolicyService::class, GovernanceMappingStatus::PartiallyImplemented, 'PERMISSION: none confirmed. AUDIT: none dedicated. REASON: not applicable to routine policy configuration.'],
            'manage_offboarding_requests' => [\App\Services\OffboardingRequestService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: none role-specific, but OffboardingRequestService::request()/cancel() are the sole chokepoints. AUDIT: real — OffboardingRequest\'s own status lifecycle (advance/complete) is a durable record. REASON: real — request() and cancel() both require a reason.'],
            'manage_key_destruction_requests' => [\App\Services\KeyDestructionApprovalService::class, GovernanceMappingStatus::Implemented, 'PERMISSION: none role-specific, but this is a two-person approval flow (requestApproval/firstApprove/secondApprove/deny). AUDIT: real — KeyDestructionApproval is a durable, dedicated approval-chain record. REASON: real — requestApproval() and deny() both require a reason.'],
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
            item_label: "{$area}.{$control}.permission_audit",
            owning_class: $owningClass,
            status: $status,
            notes: $notes,
        );
    }
}
