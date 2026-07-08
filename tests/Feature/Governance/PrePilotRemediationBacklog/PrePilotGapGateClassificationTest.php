<?php

namespace Tests\Feature\Governance\PrePilotRemediationBacklog;

use App\Services\PrePilotRemediationBacklogService;
use Tests\TestCase;

class PrePilotGapGateClassificationTest extends TestCase
{
    private PrePilotRemediationBacklogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PrePilotRemediationBacklogService();
    }

    public function test_each_gate_bucket_accessor_is_non_empty_where_expected(): void
    {
        $this->assertNotEmpty($this->service->realPilotDataBlockers());
        $this->assertNotEmpty($this->service->clientPortalMobileBlockers());
        $this->assertNotEmpty($this->service->paymentPilotBlockers());
        $this->assertNotEmpty($this->service->trustPilotBlockers());
        $this->assertNotEmpty($this->service->aiPilotBlockers());
        $this->assertNotEmpty($this->service->dedicatedPrivateEnterpriseBlockers());
        $this->assertNotEmpty($this->service->productionHardeningBlockers());
        $this->assertNotEmpty($this->service->postPilotBacklog());
    }

    public function test_high_risk_real_data_and_security_gaps_are_not_classified_as_post_pilot(): void
    {
        $postPilotKeys = array_keys($this->service->postPilotBacklog());

        foreach ([
            'rls_prepared_not_enforced',
            'firm_user_2fa_missing',
            'emergency_support_access_high_risk_approval_not_wired',
            'login_policy_wrappers_missing',
        ] as $highRiskKey) {
            $this->assertNotContains($highRiskKey, $postPilotKeys, "High-risk gap '{$highRiskKey}' must not be deferred to post-pilot backlog.");
        }
    }

    public function test_payment_gaps_are_not_classified_as_safe_before_payment_pilot(): void
    {
        $paymentBlockerKeys = array_keys($this->service->paymentPilotBlockers());
        $postPilotKeys = array_keys($this->service->postPilotBacklog());

        foreach (['stripe_disconnect_payment_collection_block_not_enforced', 'client_facing_payment_receipts_missing'] as $paymentKey) {
            $this->assertContains($paymentKey, $paymentBlockerKeys, "Payment gap '{$paymentKey}' must be classified as a payment-pilot blocker.");
            $this->assertNotContains($paymentKey, $postPilotKeys, "Payment gap '{$paymentKey}' must not be classified as safe/deferred before the payment pilot.");
        }
    }

    public function test_trust_gaps_are_not_classified_as_safe_before_trust_pilot(): void
    {
        $trustBlockerKeys = array_keys($this->service->trustPilotBlockers());
        $postPilotKeys = array_keys($this->service->postPilotBacklog());

        $this->assertContains('trust_ledger_entry_posting_actor_not_guaranteed', $trustBlockerKeys);
        $this->assertNotContains('trust_ledger_entry_posting_actor_not_guaranteed', $postPilotKeys);
    }

    public function test_ai_gaps_are_not_classified_as_safe_before_ai_pilot(): void
    {
        $aiBlockerKeys = array_keys($this->service->aiPilotBlockers());
        $postPilotKeys = array_keys($this->service->postPilotBacklog());

        foreach (['ai_jobs_not_cancelled_when_entitlement_removed', 'integration_degradation_registry_missing_ai_sms_whatsapp'] as $aiKey) {
            $this->assertContains($aiKey, $aiBlockerKeys, "AI gap '{$aiKey}' must be classified as an ai-pilot blocker.");
            $this->assertNotContains($aiKey, $postPilotKeys, "AI gap '{$aiKey}' must not be classified as safe/deferred before the AI pilot.");
        }
    }

    public function test_by_pilot_gate_matches_the_dedicated_accessors(): void
    {
        $this->assertSame(
            array_keys($this->service->byPilotGate(PrePilotRemediationBacklogService::GATE_REAL_PILOT_DATA)),
            array_keys($this->service->realPilotDataBlockers()),
        );

        $this->assertSame(
            array_keys($this->service->byPilotGate(PrePilotRemediationBacklogService::GATE_PRODUCTION_HARDENING)),
            array_keys($this->service->productionHardeningBlockers()),
        );
    }

    public function test_every_gap_appears_in_at_least_one_gate_bucket(): void
    {
        $allKeys = array_keys($this->service->all());

        $unionOfBuckets = array_unique(array_merge(
            array_keys($this->service->realPilotDataBlockers()),
            array_keys($this->service->clientPortalMobileBlockers()),
            array_keys($this->service->paymentPilotBlockers()),
            array_keys($this->service->trustPilotBlockers()),
            array_keys($this->service->aiPilotBlockers()),
            array_keys($this->service->dedicatedPrivateEnterpriseBlockers()),
            array_keys($this->service->productionHardeningBlockers()),
            array_keys($this->service->postPilotBacklog()),
        ));

        foreach ($allKeys as $key) {
            $this->assertContains($key, $unionOfBuckets, "Gap '{$key}' must appear in at least one pilot-gate bucket.");
        }
    }
}
