<?php

namespace Tests\Feature\Governance\FinalExecutiveRecommendation;

use App\Enums\GovernanceMappingStatus;
use App\Services\FinalExecutiveReadinessMappingService;
use App\ValueObjects\ExecutiveReadinessSummary;
use App\ValueObjects\GovernanceMappingResult;
use Tests\TestCase;

class FinalExecutiveReadinessMappingServiceTest extends TestCase
{
    private const PILOT_LAUNCH_KEYS = [
        'usa_saas_law_firm_pilot',
        'immigration_first_practice_area',
        'controlled_lead_intake_document_matter_workflow',
        'flat_fee_billing_payment_plans',
        'safe_operating_payments',
        'strong_onboarding',
    ];

    private const ARCHITECTURE_PRESERVATION_KEYS = [
        'multi_practice_expansion',
        'organization_billing_account_hierarchy',
        'license_module_control',
        'dedicated_private_deployment_modes',
        'ai_governance',
        'future_trust_accounting',
        'no_premature_trust_fund_exposure',
    ];

    private const WORKFLOW_AUTOMATION_KEYS = [
        'reduces_client_chasing',
        'improves_matter_readiness',
        'collects_flat_fees_reliably',
        'standardizes_practice_area_operations',
        'platform_owner_commercial_controls',
    ];

    private const STRUCTURAL_COMMITMENT_KEYS = [
        'org_billing_account_phase_1_data_contract',
        'payment_plans_consent_capture_early_scope',
        'fleet_migration_offline_licensing_before_dedicated_deal',
    ];

    private const ONE_PRODUCT_NO_FORK_KEYS = [
        'single_firm_model',
        'single_entitlement_service',
        'single_tenant_context_resolver',
        'single_license_validation_service',
        'single_module_catalog',
        'dedicated_private_customization_surfaces',
        'no_duplicate_readiness_system',
    ];

    private FinalExecutiveReadinessMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FinalExecutiveReadinessMappingService();
    }

    public function test_summary_returns_an_executive_readiness_summary(): void
    {
        $this->assertInstanceOf(ExecutiveReadinessSummary::class, $this->service->summary());
    }

    public function test_all_six_pilot_launch_readiness_keys_exist(): void
    {
        $keys = array_map(fn (GovernanceMappingResult $item) => $item->item_key, $this->service->pilotLaunchReadiness());

        $this->assertCount(6, $keys);
        foreach (self::PILOT_LAUNCH_KEYS as $key) {
            $this->assertContains($key, $keys, "Missing pilot launch readiness key: {$key}");
        }
    }

    public function test_all_seven_architecture_preservation_keys_exist(): void
    {
        $keys = array_map(fn (GovernanceMappingResult $item) => $item->item_key, $this->service->architecturePreservation());

        $this->assertCount(7, $keys);
        foreach (self::ARCHITECTURE_PRESERVATION_KEYS as $key) {
            $this->assertContains($key, $keys, "Missing architecture preservation key: {$key}");
        }
    }

    public function test_all_five_workflow_automation_differentiation_keys_exist(): void
    {
        $keys = array_map(fn (GovernanceMappingResult $item) => $item->item_key, $this->service->workflowAutomationDifferentiation());

        $this->assertCount(5, $keys);
        foreach (self::WORKFLOW_AUTOMATION_KEYS as $key) {
            $this->assertContains($key, $keys, "Missing workflow automation differentiation key: {$key}");
        }
    }

    public function test_all_three_structural_commitments_keys_exist(): void
    {
        $keys = array_map(fn (GovernanceMappingResult $item) => $item->item_key, $this->service->structuralCommitments());

        $this->assertCount(3, $keys);
        foreach (self::STRUCTURAL_COMMITMENT_KEYS as $key) {
            $this->assertContains($key, $keys, "Missing structural commitment key: {$key}");
        }
    }

    public function test_all_seven_one_product_no_fork_keys_exist(): void
    {
        $keys = array_map(fn (GovernanceMappingResult $item) => $item->item_key, $this->service->oneProductNoForkStrategy());

        $this->assertCount(7, $keys);
        foreach (self::ONE_PRODUCT_NO_FORK_KEYS as $key) {
            $this->assertContains($key, $keys, "Missing one-product/no-fork key: {$key}");
        }
    }

    public function test_known_open_gaps_reads_current_compliance_gap_registry_service_output(): void
    {
        $registryGaps = (new \App\Services\ComplianceGapRegistryService())->all();
        $serviceGaps = $this->service->knownOpenGaps();

        $this->assertCount(count($registryGaps), $serviceGaps);
        $this->assertSame(
            array_map(fn ($g) => $g->key, $registryGaps),
            array_map(fn ($g) => $g->key, $serviceGaps),
        );
    }

    public function test_dedicated_private_deal_blockers_contains_rls_and_emergency_access_blockers(): void
    {
        $blockerKeys = array_map(fn ($g) => $g->key, $this->service->dedicatedPrivateDealBlockers());

        $this->assertContains('rls_prepared_not_enforced', $blockerKeys);
        $this->assertContains('emergency_support_access_high_risk_approval_not_wired', $blockerKeys);
    }

    public function test_narrow_usa_saas_immigration_pilot_is_not_marked_blocked_solely_because_of_dedicated_private_blockers(): void
    {
        $pilotItem = null;
        foreach ($this->service->pilotLaunchReadiness() as $item) {
            if ($item->item_key === 'usa_saas_law_firm_pilot') {
                $pilotItem = $item;
            }
        }

        $this->assertNotNull($pilotItem);
        $this->assertSame(GovernanceMappingStatus::Implemented, $pilotItem->status);

        $blockerKeys = array_map(fn ($g) => $g->key, $this->service->dedicatedPrivateDealBlockers());
        $this->assertNotEmpty($blockerKeys, 'Blockers must be real for this test to be meaningful.');
    }

    public function test_every_result_has_evidence_or_notes(): void
    {
        $allMatrices = array_merge(
            $this->service->pilotLaunchReadiness(),
            $this->service->architecturePreservation(),
            $this->service->workflowAutomationDifferentiation(),
            $this->service->structuralCommitments(),
            $this->service->oneProductNoForkStrategy(),
        );

        foreach ($allMatrices as $item) {
            $this->assertNotEmpty($item->notes, "Item {$item->item_key} should have explanatory notes.");
        }
    }
}
