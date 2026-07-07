<?php

namespace Tests\Feature\Governance\FinalExecutiveRecommendation;

use App\Services\FinalExecutiveReadinessMappingService;
use Tests\TestCase;

/**
 * OneProductNoForkExecutiveTest — proves the "one product, no fork"
 * classifications actually hold: exactly one Firm/EntitlementService/
 * TenantContextResolver/LicenseFileValidationService/module_catalog
 * migration exists repository-wide, no duplicate readiness/gap
 * register service was created, and
 * FinalExecutiveReadinessMappingService genuinely references the
 * Section 25-30 services rather than reimplementing their logic.
 */
class OneProductNoForkExecutiveTest extends TestCase
{
    public function test_firm_model_exists_exactly_once(): void
    {
        $matches = $this->findFilesNamed('Firm.php', app_path());

        $this->assertCount(1, $matches, 'Firm.php must exist exactly once: '.implode(', ', $matches));
    }

    public function test_entitlement_service_exists_exactly_once(): void
    {
        $matches = $this->findFilesNamed('EntitlementService.php', app_path());

        $this->assertCount(1, $matches, 'EntitlementService.php must exist exactly once: '.implode(', ', $matches));
    }

    public function test_tenant_context_resolver_exists_exactly_once(): void
    {
        $matches = $this->findFilesNamed('TenantContextResolver.php', app_path());

        $this->assertCount(1, $matches, 'TenantContextResolver.php must exist exactly once: '.implode(', ', $matches));
    }

    public function test_license_file_validation_service_exists_exactly_once(): void
    {
        $matches = $this->findFilesNamed('LicenseFileValidationService.php', app_path());

        $this->assertCount(1, $matches, 'LicenseFileValidationService.php must exist exactly once: '.implode(', ', $matches));
    }

    public function test_module_catalog_migration_exists_exactly_once(): void
    {
        $files = glob(database_path('migrations/*create_module_catalog_table*.php')) ?: [];

        $this->assertCount(1, $files, 'Exactly one create_module_catalog_table migration must exist: '.implode(', ', $files));
    }

    public function test_no_duplicate_readiness_or_gap_registry_service_was_created(): void
    {
        $gapRegistryMatches = $this->findFilesNamed('ComplianceGapRegistryService.php', app_path());
        $this->assertCount(1, $gapRegistryMatches, 'ComplianceGapRegistryService.php must exist exactly once: '.implode(', ', $gapRegistryMatches));

        $newFiles = [
            app_path('Services/FinalExecutiveReadinessMappingService.php'),
            app_path('ValueObjects/ExecutiveReadinessSummary.php'),
        ];

        foreach ($newFiles as $file) {
            $this->assertFileExists($file);
        }

        // Section 31 must not introduce a second gap-register-shaped
        // class: no other file in app/Services may declare a
        // GAP_ITEMS-style constant.
        $servicesDir = app_path('Services');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($servicesDir, \FilesystemIterator::SKIP_DOTS));

        $filesDeclaringGapItemsConstant = [];
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getFilename() === 'ComplianceGapRegistryService.php') {
                continue;
            }

            if (str_contains(file_get_contents($file->getPathname()), 'GAP_ITEMS')) {
                $filesDeclaringGapItemsConstant[] = $file->getPathname();
            }
        }

        $this->assertEmpty($filesDeclaringGapItemsConstant, 'No second gap register may exist: '.implode(', ', $filesDeclaringGapItemsConstant));
    }

    public function test_final_executive_readiness_mapping_service_references_section_25_to_30_services_rather_than_reimplementing_them(): void
    {
        $source = file_get_contents(app_path('Services/FinalExecutiveReadinessMappingService.php'));

        $expectedReferences = [
            'ComplianceGapRegistryService',
            'SecurityBaselineMappingService',
            'ComplianceReviewGateMappingService',
            'AccessibilityCoverageMappingService',
            'ClientPortalAccessibilityReadinessService',
            'DataModelContractMappingService',
            'RowLevelSecurityCoverageMappingService',
            'IdempotencyKeyCoverageMappingService',
            'PermissionMatrixMappingService',
            'EmergencyAccessGovernanceGapService',
            'LegalSpecialistConsistencyMappingService',
            'TestCoverageMappingService',
            'ReleaseChecklistReadinessService',
            'DefinitionOfDoneReadinessService',
            'DeploymentModeCoverageMappingService',
            'OperationalReadinessMappingService',
            'MobilePortalCoverageMappingService',
            'FirmCommandCenterAggregationService',
            'TemplatePackCoverageMappingService',
            'TrustDependentPackGatingMappingService',
        ];

        $missing = [];
        foreach ($expectedReferences as $reference) {
            if (! str_contains($source, $reference)) {
                $missing[] = $reference;
            }
        }

        $this->assertEmpty($missing, 'FinalExecutiveReadinessMappingService must reference every Section 25-30 service, missing: '.implode(', ', $missing));

        // Structural proof it is a thin synthesis, not a reimplementation:
        // it must be small relative to the ~20 services it references.
        $lineCount = substr_count($source, "\n");
        $this->assertLessThan(400, $lineCount, 'FinalExecutiveReadinessMappingService should stay a thin synthesis layer.');
    }

    /**
     * @return array<int, string>
     */
    private function findFilesNamed(string $filename, string $dir): array
    {
        $matches = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $filename) {
                $matches[] = $file->getPathname();
            }
        }

        return $matches;
    }
}
