<?php

namespace Tests\Feature\Governance\PrePilotRemediationBacklog;

use App\Services\PrePilotRemediationBacklogService;
use Tests\TestCase;

class PrePilotFinalOrderTest extends TestCase
{
    private PrePilotRemediationBacklogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PrePilotRemediationBacklogService();
    }

    public function test_final_recommended_order_has_thirteen_ordered_steps(): void
    {
        $order = $this->service->finalRecommendedOrder();

        $this->assertCount(13, $order);

        foreach ($order as $index => $step) {
            $this->assertSame($index + 1, $step['step']);
        }
    }

    public function test_gap_remediation_precedes_ui_build_steps(): void
    {
        $order = $this->service->finalRecommendedOrder();
        $titles = array_column($order, 'title');

        $gapFixIndex = array_search('Fix real-pilot-data blockers.', $titles, true);
        $uiBuildIndex = array_search('Build minimal admin/firm-internal UI only.', $titles, true);

        $this->assertNotFalse($gapFixIndex);
        $this->assertNotFalse($uiBuildIndex);
        $this->assertLessThan($uiBuildIndex, $gapFixIndex, 'Real-pilot-data gap remediation must precede internal UI build.');
    }

    public function test_internal_ui_precedes_client_portal(): void
    {
        $order = $this->service->finalRecommendedOrder();
        $titles = array_column($order, 'title');

        $internalUiIndex = array_search('Build minimal admin/firm-internal UI only.', $titles, true);
        $clientPortalFixIndex = array_search('Fix client-portal/mobile blockers.', $titles, true);
        $clientPortalBuildIndex = array_search('Build minimal client portal/mobile if still desired.', $titles, true);

        $this->assertLessThan($clientPortalFixIndex, $internalUiIndex);
        $this->assertLessThan($clientPortalBuildIndex, $clientPortalFixIndex);
    }

    public function test_payment_remediation_precedes_payment_ui(): void
    {
        $order = $this->service->finalRecommendedOrder();
        $titles = array_column($order, 'title');

        $paymentFixIndex = array_search('Fix payment-pilot blockers.', $titles, true);
        $paymentUiIndex = array_search('Build payment UI/payment collection pilot.', $titles, true);

        $this->assertLessThan($paymentUiIndex, $paymentFixIndex);
    }

    public function test_hardening_precedes_trust_and_ai_gates(): void
    {
        $order = $this->service->finalRecommendedOrder();
        $titles = array_column($order, 'title');

        $hardeningIndex = array_search('Complete production hardening blockers.', $titles, true);
        $trustFixIndex = array_search('Fix trust-pilot blockers.', $titles, true);
        $aiFixIndex = array_search('Fix AI-pilot blockers.', $titles, true);

        $this->assertLessThan($trustFixIndex, $hardeningIndex);
        $this->assertLessThan($aiFixIndex, $hardeningIndex);
    }

    public function test_trust_and_ai_gates_are_each_internally_ordered_fix_before_build(): void
    {
        $order = $this->service->finalRecommendedOrder();
        $titles = array_column($order, 'title');

        $trustFixIndex = array_search('Fix trust-pilot blockers.', $titles, true);
        $trustBuildIndex = array_search('Build trust UI/pilot if desired.', $titles, true);
        $aiFixIndex = array_search('Fix AI-pilot blockers.', $titles, true);
        $aiBuildIndex = array_search('Build AI UI/pilot if desired.', $titles, true);

        $this->assertLessThan($trustBuildIndex, $trustFixIndex);
        $this->assertLessThan($aiBuildIndex, $aiFixIndex);
    }

    public function test_pilot_launch_package_precedes_inviting_firms(): void
    {
        $order = $this->service->finalRecommendedOrder();
        $titles = array_column($order, 'title');

        $launchPackageIndex = array_search('Prepare pilot launch package: legal docs, support workflow, demo data, training, onboarding.', $titles, true);
        $inviteFirmsIndex = array_search('Invite first pilot firms.', $titles, true);

        $this->assertNotFalse($launchPackageIndex);
        $this->assertNotFalse($inviteFirmsIndex);
        $this->assertLessThan($inviteFirmsIndex, $launchPackageIndex);
        $this->assertSame(count($titles) - 1, $inviteFirmsIndex, 'Inviting first pilot firms must be the final step.');
    }

    public function test_acceptance_tests_before_gate_returns_relevant_tests_for_each_named_gate(): void
    {
        foreach ([
            PrePilotRemediationBacklogService::GATE_REAL_PILOT_DATA,
            PrePilotRemediationBacklogService::GATE_PAYMENT_PILOT,
            PrePilotRemediationBacklogService::GATE_TRUST_PILOT,
            PrePilotRemediationBacklogService::GATE_AI_PILOT,
            PrePilotRemediationBacklogService::GATE_CLIENT_PORTAL_MOBILE,
            PrePilotRemediationBacklogService::GATE_DEDICATED_PRIVATE_ENTERPRISE,
        ] as $gate) {
            $tests = $this->service->acceptanceTestsBeforeGate($gate);

            $this->assertNotEmpty($tests, "acceptanceTestsBeforeGate('{$gate}') must return relevant tests.");
        }
    }

    public function test_remediation_order_is_fully_sequenced_one_through_twenty_one(): void
    {
        $order = $this->service->remediationOrder();

        $this->assertCount(21, $order);

        $orders = array_column($order, 'order');
        sort($orders);

        $this->assertSame(range(1, 21), $orders);
    }
}
