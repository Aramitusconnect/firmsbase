<?php

namespace Tests\Feature\Governance\AdminControlCatalog;

use App\Enums\GovernanceMappingStatus;
use App\Services\AdminControlCatalogMappingService;
use App\ValueObjects\GovernanceMappingResult;
use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

class AdminControlCatalogMappingServiceTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'organization_management.create_organizations', 'organization_management.create_billing_accounts',
        'organization_management.attach_detach_firms', 'organization_management.assign_org_licenses',
        'organization_management.configure_seat_pools', 'organization_management.allocate_seats',
        'organization_management.set_conflict_scope_posture', 'organization_management.view_consolidated_invoices',
        'organization_management.view_usage_attribution',

        'firm_management.create_firm', 'firm_management.edit_firm_settings', 'firm_management.set_customer_type',
        'firm_management.set_deployment_mode', 'firm_management.set_jurisdiction',
        'firm_management.assign_implementation_owner', 'firm_management.activate_deactivate_firm',
        'firm_management.view_health_score',

        'plan_license_management.create_plans', 'plan_license_management.edit_prices',
        'plan_license_management.set_seat_classes_limits', 'plan_license_management.assign_licenses',
        'plan_license_management.suspend_reactivate_licenses', 'plan_license_management.configure_trials',
        'plan_license_management.approve_custom_overrides', 'plan_license_management.issue_signed_license_files',
        'plan_license_management.view_license_history',

        'module_entitlements.enable_disable_modules_by_plan', 'module_entitlements.enable_disable_modules_by_organization',
        'module_entitlements.enable_disable_modules_by_firm', 'module_entitlements.set_entitlement_start_end_dates',
        'module_entitlements.require_admin_approval', 'module_entitlements.enforce_backend_access',
        'module_entitlements.log_override_reasons_sources',

        'ai_controls.enable_disable_ai', 'ai_controls.choose_platform_managed_or_firm_owned_ai',
        'ai_controls.set_provider_model_allowlist', 'ai_controls.set_token_budget_limits_firm',
        'ai_controls.set_token_budget_limits_organization', 'ai_controls.require_ai_approvals',
        'ai_controls.disable_document_client_context', 'ai_controls.review_retrieval_isolation_status',

        'payment_controls.enable_disable_payments', 'payment_controls.enable_disable_payment_plans',
        'payment_controls.approve_stripe_setup', 'payment_controls.configure_operating_only_mode',
        'payment_controls.block_trust_deposits', 'payment_controls.review_payment_classification_events',
        'payment_controls.handle_failed_webhooks',

        'trust_controls.approve_trust_mode_activation', 'trust_controls.configure_trust_accounts',
        'trust_controls.review_reconciliations', 'trust_controls.approve_high_risk_trust_actions',
        'trust_controls.enforce_jurisdiction_controls',

        'template_controls.create_template_packs', 'template_controls.version_template_packs',
        'template_controls.publish_unpublish_template_packs', 'template_controls.install_template_packs_for_firms',
        'template_controls.preview_template_upgrades', 'template_controls.manage_form_edition_watch_queue',
        'template_controls.manage_form_edition_slas', 'template_controls.audit_template_changes',

        'deployment_fleet.view_fleet_version_skew', 'deployment_fleet.plan_fleet_migrations',
        'deployment_fleet.execute_fleet_migrations', 'deployment_fleet.halt_fleet_migrations',
        'deployment_fleet.roll_back_fleet_migrations', 'deployment_fleet.review_degradation_mode_status',
        'deployment_fleet.review_health_envelope_reports',

        'support_controls.request_firm_approved_access', 'support_controls.approve_emergency_support_access',
        'support_controls.set_support_access_time_limit', 'support_controls.require_support_access_reason',
        'support_controls.notify_firm_of_support_access', 'support_controls.audit_support_actions',

        'customer_success.view_onboarding_progress', 'customer_success.view_risk_flags',
        'customer_success.view_usage_analytics', 'customer_success.view_failed_jobs',
        'customer_success.view_document_chase_performance', 'customer_success.view_payment_plan_collection_performance',
        'customer_success.view_trial_conversion_progress',

        'operations.manage_announcements', 'operations.manage_release_notes', 'operations.manage_status_incidents',
        'operations.manage_vendor_register', 'operations.manage_access_reviews', 'operations.manage_retention_policies',
        'operations.manage_offboarding_requests', 'operations.manage_key_destruction_requests',
    ];

    private AdminControlCatalogMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AdminControlCatalogMappingService();
    }

    public function test_all_eighty_nine_admin_control_keys_are_explicitly_declared(): void
    {
        $declaredKeys = array_keys($this->service->all());

        $this->assertCount(89, $declaredKeys);

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required admin control key: {$key}");
        }
    }

    public function test_no_duplicate_keys_exist(): void
    {
        $keys = array_keys($this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate admin control key(s) found.');
    }

    public function test_all_entries_return_governance_mapping_result(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertInstanceOf(GovernanceMappingResult::class, $item, "Entry {$key} must be a GovernanceMappingResult.");
        }
    }

    public function test_every_entry_has_notes_or_evidence(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertNotEmpty($item->notes, "Item {$key} should have explanatory notes.");
        }
    }

    public function test_backend_only_includes_controls_that_lack_ui(): void
    {
        $backendOnly = array_keys($this->service->backendOnly());

        $this->assertContains('trust_controls.approve_trust_mode_activation', $backendOnly);
        $this->assertContains('ai_controls.require_ai_approvals', $backendOnly);
        $this->assertNotEmpty($backendOnly);
    }

    public function test_ui_backed_reflects_actual_aws_ui_inspection_and_is_empty(): void
    {
        // AWS confirmed app/Filament does not exist at all (only the
        // empty Laravel/Filament AdminPanelProvider scaffold, which
        // discovers zero resources/pages) — no control has real UI.
        $this->assertEmpty($this->service->uiBacked());
        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
    }

    public function test_dangerous_before_hardening_includes_emergency_support_access_while_the_gap_remains(): void
    {
        $registry = new ComplianceGapRegistryService();
        $this->assertTrue($registry->isTracked('emergency_support_access_high_risk_approval_not_wired'), 'This test assumes the existing emergency-access gap is still open.');

        $dangerous = array_keys($this->service->dangerousBeforeHardening());

        $this->assertContains('support_controls.approve_emergency_support_access', $dangerous);
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist.control'));
    }

    public function test_areas_returns_all_twelve_admin_areas(): void
    {
        $this->assertCount(12, $this->service->areas());
        $this->assertContains('organization_management', $this->service->areas());
        $this->assertContains('operations', $this->service->areas());
    }
}
