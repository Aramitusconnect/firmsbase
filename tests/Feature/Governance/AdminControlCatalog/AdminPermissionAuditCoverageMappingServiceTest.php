<?php

namespace Tests\Feature\Governance\AdminControlCatalog;

use App\Services\AdminControlCatalogMappingService;
use App\Services\AdminPermissionAuditCoverageMappingService;
use App\Services\ComplianceGapRegistryService;
use App\ValueObjects\GovernanceMappingResult;
use Tests\TestCase;

class AdminPermissionAuditCoverageMappingServiceTest extends TestCase
{
    private AdminPermissionAuditCoverageMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AdminPermissionAuditCoverageMappingService();
    }

    public function test_all_eighty_nine_controls_have_permission_audit_coverage_entries(): void
    {
        $catalogKeys = array_keys((new AdminControlCatalogMappingService())->all());
        $coverageKeys = array_keys($this->service->all());

        $this->assertCount(89, $coverageKeys);
        sort($catalogKeys);
        sort($coverageKeys);
        $this->assertSame($catalogKeys, $coverageKeys, 'Coverage keys must exactly match the admin control catalog keys.');
    }

    public function test_all_entries_return_governance_mapping_result(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertInstanceOf(GovernanceMappingResult::class, $item, "Entry {$key} must be a GovernanceMappingResult.");
        }
    }

    public function test_high_risk_controls_have_audit_evidence(): void
    {
        $highRiskKeys = [
            'trust_controls.approve_trust_mode_activation',
            'trust_controls.approve_high_risk_trust_actions',
            'plan_license_management.suspend_reactivate_licenses',
            'module_entitlements.log_override_reasons_sources',
            'ai_controls.require_ai_approvals',
            'operations.manage_offboarding_requests',
            'operations.manage_key_destruction_requests',
        ];

        $auditBackedKeys = array_keys($this->service->auditBacked());

        foreach ($highRiskKeys as $key) {
            $this->assertContains($key, $auditBackedKeys, "{$key} is high-risk and must have audit evidence.");
        }
    }

    public function test_high_risk_override_support_trust_key_destruction_controls_have_reason_evidence(): void
    {
        $reasonRequiredKeys = [
            'plan_license_management.suspend_reactivate_licenses',
            'module_entitlements.log_override_reasons_sources',
            'trust_controls.approve_trust_mode_activation',
            'trust_controls.approve_high_risk_trust_actions',
            'support_controls.require_support_access_reason',
            'operations.manage_offboarding_requests',
            'operations.manage_key_destruction_requests',
        ];

        $reasonBackedKeys = array_keys($this->service->reasonBacked());

        foreach ($reasonRequiredKeys as $key) {
            $this->assertContains($key, $reasonBackedKeys, "{$key} is high-risk/override-based and must have reason evidence.");
        }
    }

    public function test_org_scoped_controls_reference_org_admin_role_missing_gap_without_duplicating_it(): void
    {
        $registry = new ComplianceGapRegistryService();
        $this->assertTrue($registry->isTracked('org_admin_role_missing'));

        $orgControls = array_filter(
            $this->service->all(),
            fn (string $key) => str_starts_with($key, 'organization_management.'),
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertNotEmpty($orgControls);

        // No duplicate org_admin gap key anywhere in this service's notes.
        foreach ($orgControls as $item) {
            $this->assertStringNotContainsString('org_admin_role_missing_duplicate', $item->notes);
        }
    }

    public function test_emergency_support_access_references_existing_gap_without_duplicating_it(): void
    {
        $item = $this->service->byKey('support_controls.approve_emergency_support_access');

        $this->assertNotNull($item);
        $this->assertStringContainsString('emergency_support_access_high_risk_approval_not_wired', $item->notes);

        $registry = new ComplianceGapRegistryService();
        $keys = array_map(fn ($g) => $g->key, $registry->all());
        $emergencyAccessKeys = array_filter($keys, fn (string $key) => str_contains($key, 'emergency_support_access'));

        $this->assertCount(1, $emergencyAccessKeys, 'Exactly one emergency-support-access gap should exist in the registry — no duplicate was added.');
    }

    public function test_no_second_audit_system_is_introduced(): void
    {
        $source = file_get_contents(app_path('Services/AdminPermissionAuditCoverageMappingService.php'));

        $this->assertStringNotContainsString('Schema::create', $source);
        $this->assertStringNotContainsString("'security_events'", $source);
        $this->assertStringNotContainsString("'timeline_events'", $source);
    }

    public function test_dangerous_before_ui_contains_emergency_support_access(): void
    {
        $keys = array_keys($this->service->dangerousBeforeUi());

        $this->assertContains('support_controls.approve_emergency_support_access', $keys);
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist.control'));
    }
}
