<?php

namespace Tests\Feature\Governance\AcceptanceTestMatrix;

use App\Services\AcceptanceTestMatrixMappingService;
use Tests\TestCase;

class AcceptancePilotGateClassificationTest extends TestCase
{
    private AcceptanceTestMatrixMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AcceptanceTestMatrixMappingService();
    }

    public function test_pilot_gate_accessors_return_non_empty_relevant_sets(): void
    {
        $this->assertNotEmpty($this->service->saasPilot());
        $this->assertNotEmpty($this->service->dedicatedPrivateEnterprise());
        $this->assertNotEmpty($this->service->paymentPilot());
        $this->assertNotEmpty($this->service->trustPilot());
        $this->assertNotEmpty($this->service->aiPilot());
        $this->assertNotEmpty($this->service->clientPortalMobileLaunch());
    }

    public function test_production_blockers_includes_true_security_and_data_blockers_only(): void
    {
        $blockerKeys = array_keys($this->service->productionBlockers());

        $this->assertContains('tenant_isolation.rls_broken_scope', $blockerKeys);
        $this->assertContains('documents.virus_scan', $blockerKeys);
        $this->assertContains('documents.signed_urls_via_tenant_context', $blockerKeys);
        $this->assertContains('security.emergency_access_audit', $blockerKeys);

        // UI-absence findings must never appear as production blockers.
        $this->assertNotContains('accessibility_mobile.camera_upload', $blockerKeys);
        $this->assertNotContains('security.two_factor_authentication', $blockerKeys);
    }

    public function test_client_portal_mobile_blockers_are_not_incorrectly_classified_as_current_saas_pilot_blockers(): void
    {
        $saasKeys = array_keys($this->service->saasPilot());
        $mobileKeys = array_keys($this->service->clientPortalMobileLaunch());

        foreach ($mobileKeys as $key) {
            if (str_starts_with($key, 'accessibility_mobile.')) {
                $this->assertNotContains($key, $saasKeys, "{$key} must not be treated as a current SaaS-pilot requirement.");
            }
        }
    }

    public function test_payment_gate_includes_domain_specific_billing_tests(): void
    {
        $keys = array_keys($this->service->paymentPilot());

        $this->assertContains('billing.invoice_lifecycle', $keys);
        $this->assertContains('billing.double_submit_prevention', $keys);
        $this->assertContains('billing.stripe_classification_before_intent', $keys);
    }

    public function test_trust_gate_includes_domain_specific_trust_tests(): void
    {
        $keys = array_keys($this->service->trustPilot());

        $this->assertContains('trust.eligible_firm_activation', $keys);
        $this->assertContains('trust.concurrent_withdrawal', $keys);
        $this->assertContains('trust.refund_chargeback_flow', $keys);
    }

    public function test_ai_gate_includes_domain_specific_ai_tests(): void
    {
        $keys = array_keys($this->service->aiPilot());

        $this->assertContains('ai.high_risk_approval', $keys);
        $this->assertContains('ai.retrieval_isolation_no_unauthorized_matter_or_cross_firm_context', $keys);
        $this->assertContains('ai.prompt_injection_resistance', $keys);
    }

    public function test_dedicated_private_enterprise_gate_includes_reliability_fleet_and_rls_resolution(): void
    {
        $keys = array_keys($this->service->dedicatedPrivateEnterprise());

        $this->assertContains('reliability_fleet.backup', $keys);
        $this->assertContains('reliability_fleet.fleet_migration_rehearsal_halt_rollback', $keys);
        $this->assertContains('tenant_isolation.rls_broken_scope', $keys);
    }

    public function test_pilot_gate_accessor_matches_dedicated_switch_method(): void
    {
        $this->assertSame(
            array_keys($this->service->saasPilot()),
            array_keys($this->service->pilotGate('saas')),
        );
    }
}
