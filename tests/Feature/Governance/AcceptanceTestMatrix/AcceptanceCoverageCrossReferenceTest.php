<?php

namespace Tests\Feature\Governance\AcceptanceTestMatrix;

use App\Enums\GovernanceMappingStatus;
use App\Services\AcceptanceTestMatrixMappingService;
use App\Services\TestCoverageMappingService;
use Tests\TestCase;

/**
 * AcceptanceCoverageCrossReferenceTest — proves
 * AcceptanceTestMatrixMappingService synthesizes/cross-references
 * Section 28's TestCoverageMappingService rather than replacing or
 * contradicting it.
 */
class AcceptanceCoverageCrossReferenceTest extends TestCase
{
    private AcceptanceTestMatrixMappingService $acceptanceService;
    private TestCoverageMappingService $testCoverageService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acceptanceService = new AcceptanceTestMatrixMappingService();
        $this->testCoverageService = new TestCoverageMappingService();
    }

    public function test_overlapping_tenant_isolation_classifications_are_compatible(): void
    {
        $acceptanceRls = $this->acceptanceService->byKey('tenant_isolation.rls_broken_scope');
        $coverageRls = $this->testCoverageService->byKey('tenant_isolation_broken_scope_caught_by_rls');

        $this->assertNotNull($acceptanceRls);
        $this->assertNotNull($coverageRls);

        // Neither may claim Implemented while RLS enforcement is inactive.
        $this->assertNotSame(GovernanceMappingStatus::Implemented, $acceptanceRls->status);
        $this->assertNotSame(GovernanceMappingStatus::Implemented, $coverageRls->status);
    }

    public function test_overlapping_entitlement_precedence_classifications_are_compatible(): void
    {
        $acceptanceItem = $this->acceptanceService->byKey('entitlements.org_inheritance_override_precedence');
        $coverageItem = $this->testCoverageService->byKey('entitlement_inheritance_override_precedence');

        $this->assertNotNull($acceptanceItem);
        $this->assertNotNull($coverageItem);
        $this->assertSame(GovernanceMappingStatus::Implemented, $acceptanceItem->status);
        $this->assertSame(GovernanceMappingStatus::Implemented, $coverageItem->status);
    }

    public function test_overlapping_import_export_tenant_isolation_classifications_are_compatible(): void
    {
        $acceptanceItem = $this->acceptanceService->byKey('tenant_isolation.cross_firm_import_export');
        $coverageItem = $this->testCoverageService->byKey('import_export_tenant_isolation');

        $this->assertNotNull($acceptanceItem);
        $this->assertNotNull($coverageItem);
        $this->assertSame(GovernanceMappingStatus::Implemented, $acceptanceItem->status);
        $this->assertSame(GovernanceMappingStatus::Implemented, $coverageItem->status);
    }

    public function test_this_service_does_not_replace_or_duplicate_test_coverage_mapping_service(): void
    {
        $source = file_get_contents(app_path('Services/AcceptanceTestMatrixMappingService.php'));

        // Must reference, not redeclare, TestCoverageMappingService's
        // own 24-group constant/logic.
        $this->assertStringNotContainsString('REQUIRED_GROUP_KEYS', $source);
        $this->assertStringNotContainsString('private const GROUP_ITEMS', $source);
    }

    public function test_existing_section_25_to_35_gap_cross_references_are_used_rather_than_duplicated(): void
    {
        $keys = array_map(fn ($item) => $item->item_key, $this->acceptanceService->existingGapCrossReferences());

        $this->assertNotEmpty($keys);
        $this->assertContains('tenant_isolation.rls_broken_scope', $keys);
        $this->assertContains('documents.virus_scan', $keys);
    }
}
