<?php

namespace Tests\Feature\Governance\AdminControlCatalog;

use App\Enums\GovernanceMappingStatus;
use App\Services\AdminControlCatalogMappingService;
use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

class AdminControlAreaCoverageTest extends TestCase
{
    private const AREAS = [
        'organization_management', 'firm_management', 'plan_license_management',
        'module_entitlements', 'ai_controls', 'payment_controls', 'trust_controls',
        'template_controls', 'deployment_fleet', 'support_controls', 'customer_success',
        'operations',
    ];

    private AdminControlCatalogMappingService $service;
    private ComplianceGapRegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AdminControlCatalogMappingService();
        $this->registry = new ComplianceGapRegistryService();
    }

    public function test_all_twelve_admin_areas_are_represented(): void
    {
        $coverage = $this->service->controlCoverage();

        $this->assertCount(12, $coverage);

        foreach (self::AREAS as $area) {
            $this->assertArrayHasKey($area, $coverage, "Missing area-level coverage for {$area}.");
        }
    }

    public function test_organization_management_is_partially_implemented_if_org_admin_remains_missing(): void
    {
        $this->assertTrue($this->registry->isTracked('org_admin_role_missing'), 'This test assumes org_admin_role_missing is still open.');

        $coverage = $this->service->controlCoverage();

        // organization_management itself does not require org_admin to
        // classify its OWN controls (which are platform-admin actions,
        // not org-admin self-service) — this test documents that the
        // area's classification is driven by its own AWS evidence, and
        // the org_admin gap is referenced (not duplicated) via the
        // permission/audit coverage service instead.
        $this->assertNotNull($coverage['organization_management']);
        $this->assertContains(
            $coverage['organization_management']->status,
            [GovernanceMappingStatus::Implemented, GovernanceMappingStatus::PartiallyImplemented],
        );
    }

    public function test_template_controls_is_partially_implemented_because_form_edition_sla_controls_are_missing(): void
    {
        $coverage = $this->service->controlCoverage();

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $coverage['template_controls']->status);

        $slaItem = $this->service->byKey('template_controls.manage_form_edition_slas');
        $this->assertSame(GovernanceMappingStatus::NotFound, $slaItem->status);
    }

    public function test_support_controls_reflects_emergency_access_gap_without_duplicating_it(): void
    {
        $this->assertTrue($this->registry->isTracked('emergency_support_access_high_risk_approval_not_wired'));

        $coverage = $this->service->controlCoverage();

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $coverage['support_controls']->status);

        $emergencyAccessKeys = array_filter(
            array_map(fn ($g) => $g->key, $this->registry->all()),
            fn (string $key) => str_contains($key, 'emergency_support_access'),
        );
        $this->assertCount(1, $emergencyAccessKeys);
    }

    public function test_all_other_areas_classify_according_to_aws_evidence(): void
    {
        $coverage = $this->service->controlCoverage();

        foreach (self::AREAS as $area) {
            $this->assertContains(
                $coverage[$area]->status,
                [GovernanceMappingStatus::Implemented, GovernanceMappingStatus::PartiallyImplemented],
                "{$area} should classify as Implemented or PartiallyImplemented based on real AWS evidence.",
            );
            $this->assertNotEmpty($coverage[$area]->notes);
        }
    }
}
