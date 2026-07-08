<?php

namespace Tests\Feature\Governance\ProfessionalReviewGate;

use App\Enums\GovernanceMappingStatus;
use App\Services\ComplianceGapRegistryService;
use App\Services\ProfessionalReviewGateMappingService;
use Tests\TestCase;

/**
 * ProfessionalReviewAiImportTemplateDeploymentTest — gates 11, 13, 14,
 * 15, 17: AI retrieval gate distinguishes hard scoping from
 * metadata-only filtering (and, if metadata-only were found, the High
 * gap would exist — AWS confirmed it is NOT metadata-only, so the gap
 * must NOT exist), import preview/validation/confirmation gate,
 * template upgrade/form-edition preservation gate, dedicated/private
 * no-fork/offline-license gate, and first-dedicated-deal readiness
 * referencing fleet/offline-license rehearsal.
 */
class ProfessionalReviewAiImportTemplateDeploymentTest extends TestCase
{
    private ProfessionalReviewGateMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProfessionalReviewGateMappingService();
    }

    public function test_ai_retrieval_gate_distinguishes_hard_scoping_from_metadata_only_filtering(): void
    {
        $item = $this->service->byKey('ai.no_cross_firm_or_metadata_only_retrieval');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(\App\Services\AiRetrievalIsolationService::class, $item->owning_class);
        $this->assertStringContainsString('HARD', $item->notes);
        $this->assertStringContainsString('never a shared index filtered only by metadata', $item->notes);
    }

    public function test_ai_retrieval_isolation_service_throws_rather_than_silently_narrows(): void
    {
        $this->assertFileExists(app_path('Services/AiRetrievalIsolationService.php'));

        $source = file_get_contents(app_path('Services/AiRetrievalIsolationService.php'));

        $this->assertStringContainsString('throw new \\RuntimeException', $source);
        $this->assertStringContainsString('Cross-firm AI retrieval is never authorized', $source);
        $this->assertStringContainsString('canAccessAllMatters', $source);
    }

    public function test_ai_retrieval_hard_scope_gap_does_not_exist_because_aws_confirmed_hard_scoping(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertFalse(
            $registry->isTracked('ai_retrieval_hard_scope_not_enforced'),
            'This gap must not exist: AWS confirmed AiRetrievalIsolationService performs hard, pre-retrieval scoping, not post-retrieval metadata-only filtering.'
        );
    }

    public function test_import_preview_validation_confirmation_gate_is_evaluated(): void
    {
        $item = $this->service->byKey('imports.no_production_write_without_preview_validation_confirmation');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(\App\Services\ImportApplyService::class, $item->owning_class);
        $this->assertStringContainsString('PreviewReady', $item->notes);
    }

    public function test_template_upgrade_and_form_edition_preservation_gate_is_evaluated(): void
    {
        $item = $this->service->byKey('templates.no_silent_template_upgrade_or_historical_draft_mutation');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertStringContainsString('pinned_template_pack_version_id', $item->notes);
        $this->assertStringContainsString('Phase10RetiredVersionPreservesHistoricalDraftsTest', $item->notes);
    }

    public function test_dedicated_private_no_fork_offline_license_gate_is_evaluated(): void
    {
        $item = $this->service->byKey('deployment.no_code_fork_or_connectivity_required_license_validation');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(\App\Services\LicenseFileValidationService::class, $item->owning_class);
        $this->assertStringContainsString('offline', $item->notes);
        $this->assertStringContainsString('no per-mode fork exists anywhere', $item->notes);
    }

    public function test_first_dedicated_deal_readiness_references_fleet_and_offline_license_rehearsal(): void
    {
        $item = $this->service->byKey('dedicated_deal.no_first_deal_before_fleet_and_offline_license_rehearsal');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertSame(\App\Services\FinalExecutiveReadinessMappingService::class, $item->owning_class);
        $this->assertStringContainsString('FleetMigrationOrchestrationServiceTest', $item->notes);
        $this->assertStringContainsString('LicenseFileSigningAndValidationServiceTest', $item->notes);
        $this->assertStringContainsString('FinalExecutiveReadinessMappingService', $item->notes);
    }

    public function test_first_dedicated_deal_gate_does_not_duplicate_a_second_final_readiness_system(): void
    {
        $item = $this->service->byKey('dedicated_deal.no_first_deal_before_fleet_and_offline_license_rehearsal');

        $this->assertStringNotContainsStringIgnoringCase('second final readiness', $item->notes);
        $this->assertStringContainsString('rather than duplicating a second final-readiness system', $item->notes);
    }
}
