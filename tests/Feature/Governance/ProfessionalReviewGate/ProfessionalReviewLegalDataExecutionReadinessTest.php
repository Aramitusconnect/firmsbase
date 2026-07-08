<?php

namespace Tests\Feature\Governance\ProfessionalReviewGate;

use App\Enums\GovernanceMappingStatus;
use App\Services\ProfessionalReviewGateMappingService;
use Tests\TestCase;

/**
 * ProfessionalReviewLegalDataExecutionReadinessTest — gates 9, 10, 16:
 * cancellation/suspension/license-expiry legal-data preservation,
 * no automatic legal approval/filing implication, legal-specialist
 * language gate; also confirms productionBlockers()/
 * dedicatedPrivateDealBlockers() behave consistently with gate
 * classifications, and that FinalExecutiveReadinessMappingService is
 * referenced, not replaced.
 */
class ProfessionalReviewLegalDataExecutionReadinessTest extends TestCase
{
    private ProfessionalReviewGateMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProfessionalReviewGateMappingService();
    }

    public function test_legal_data_preservation_gate_is_evaluated(): void
    {
        $item = $this->service->byKey('legal_data.no_destructive_cancellation_suspension_or_expiry');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(\App\Services\LegalDataAccessPolicyService::class, $item->owning_class);
        $this->assertStringContainsString('LegalHoldService', $item->notes);
    }

    public function test_no_automatic_legal_approval_or_filing_implication_gate_is_evaluated(): void
    {
        $item = $this->service->byKey('legal_ai.no_customer_facing_auto_approval_or_filing_implication');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(\App\Services\AiApprovalWorkflowService::class, $item->owning_class);
        $this->assertStringContainsString('human actor', $item->notes);
    }

    public function test_legal_specialist_language_gate_is_evaluated(): void
    {
        $item = $this->service->byKey('legal_specialist.no_inappropriate_legal_language_without_configuration');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertSame(\App\Services\LegalSpecialistBoundaryPolicyService::class, $item->owning_class);
        $this->assertStringContainsString('FORBIDDEN_TERMS', $item->notes);
    }

    public function test_legal_data_preservation_accessor_matches_the_gate_key(): void
    {
        $keys = array_keys($this->service->legalDataPreservation());

        $this->assertContains('legal_data.no_destructive_cancellation_suspension_or_expiry', $keys);
    }

    public function test_production_blockers_is_empty_because_no_true_violation_was_found(): void
    {
        $this->assertEmpty($this->service->productionBlockers());
        $this->assertSame([], $this->service->overallGateStatus()['production_blockers']);
    }

    public function test_dedicated_private_deal_blockers_matches_the_partially_implemented_gate_seventeen(): void
    {
        $blockers = $this->service->dedicatedPrivateDealBlockers();

        $this->assertArrayHasKey('dedicated_deal.no_first_deal_before_fleet_and_offline_license_rehearsal', $blockers);
        $this->assertSame(
            GovernanceMappingStatus::PartiallyImplemented,
            $blockers['dedicated_deal.no_first_deal_before_fleet_and_offline_license_rehearsal']->status
        );
    }

    public function test_final_executive_readiness_mapping_service_is_referenced_not_replaced(): void
    {
        $this->assertFileExists(app_path('Services/FinalExecutiveReadinessMappingService.php'));

        // No second execution-readiness gate was created.
        $duplicateReadinessServices = glob(app_path('Services/*ExecutiveReadiness*.php')) ?: [];

        $this->assertCount(
            1,
            $duplicateReadinessServices,
            'Only FinalExecutiveReadinessMappingService may exist; a second execution-readiness system was found: '.implode(', ', $duplicateReadinessServices)
        );

        $referencingItems = array_filter(
            $this->service->all(),
            fn ($item) => $item->owning_class === \App\Services\FinalExecutiveReadinessMappingService::class,
        );

        $this->assertNotEmpty($referencingItems, 'At least one gate must reference FinalExecutiveReadinessMappingService as owning_class.');
    }

    public function test_overall_gate_status_notes_confirm_no_new_gap_was_added(): void
    {
        $status = $this->service->overallGateStatus();

        $this->assertStringContainsString('No new gap was warranted', $status['notes']);
    }
}
