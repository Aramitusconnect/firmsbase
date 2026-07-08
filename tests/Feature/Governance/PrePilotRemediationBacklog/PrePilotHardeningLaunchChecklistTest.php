<?php

namespace Tests\Feature\Governance\PrePilotRemediationBacklog;

use App\Services\PrePilotRemediationBacklogService;
use Tests\TestCase;

class PrePilotHardeningLaunchChecklistTest extends TestCase
{
    private PrePilotRemediationBacklogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PrePilotRemediationBacklogService();
    }

    public function test_production_hardening_checklist_contains_the_required_items(): void
    {
        $joined = implode(' | ', $this->service->productionHardeningChecklist());

        $this->assertStringContainsStringIgnoringCase('backup', $joined);
        $this->assertStringContainsStringIgnoringCase('restore', $joined);
        $this->assertStringContainsStringIgnoringCase('malware scanning', $joined);
        $this->assertStringContainsStringIgnoringCase('secret rotation', $joined);
        $this->assertStringContainsStringIgnoringCase('RLS enforcement', $joined);
        $this->assertStringContainsStringIgnoringCase('test/demo secret audit', $joined);
        $this->assertStringContainsStringIgnoringCase('queue worker supervision', $joined);
        $this->assertStringContainsStringIgnoringCase('scheduler supervision', $joined);
        $this->assertStringContainsStringIgnoringCase('monitoring', $joined);
        $this->assertStringContainsStringIgnoringCase('rollback', $joined);
    }

    public function test_legal_commercial_launch_checklist_contains_the_required_documents(): void
    {
        $joined = implode(' | ', $this->service->legalCommercialLaunchChecklist());

        foreach ([
            'Terms of Service', 'Privacy Policy', 'Data Processing Addendum', 'Subprocessor list',
            'Acceptable Use Policy', 'AI usage disclaimer', 'No-legal-advice disclaimer',
            'No-automatic-filing disclaimer', 'Trust/IOLTA limitation', 'Communication consent',
            'Retention/offboarding policy', 'Support/SLA policy', 'Pilot agreement',
            'Security/privacy summary',
        ] as $expectedItem) {
            $this->assertStringContainsStringIgnoringCase($expectedItem, $joined, "Legal/commercial checklist missing: {$expectedItem}");
        }
    }

    public function test_support_emergency_workflow_checklist_contains_the_required_items(): void
    {
        $joined = implode(' | ', $this->service->supportEmergencyWorkflowChecklist());

        $this->assertStringContainsStringIgnoringCase('emergency', $joined);
        $this->assertStringContainsStringIgnoringCase('approval', $joined);
        $this->assertStringContainsStringIgnoringCase('reason', $joined);
        $this->assertStringContainsStringIgnoringCase('duration', $joined);
        $this->assertStringContainsStringIgnoringCase('firm notification', $joined);
        $this->assertStringContainsStringIgnoringCase('audit trail', $joined);
        $this->assertStringContainsStringIgnoringCase('escalation', $joined);
    }

    public function test_demo_sandbox_data_checklist_requires_synthetic_only_and_no_real_secrets(): void
    {
        $joined = implode(' | ', $this->service->demoSandboxDataChecklist());

        $this->assertStringContainsStringIgnoringCase('synthetic only', $joined);
        $this->assertStringContainsStringIgnoringCase('no real client/legal data', $joined);
        $this->assertStringContainsStringIgnoringCase('no real secrets/API keys', $joined);
        $this->assertStringContainsStringIgnoringCase('seeder/factory audit', $joined);
    }

    public function test_support_emergency_workflow_checklist_references_the_existing_emergency_access_gap(): void
    {
        $joined = implode(' | ', $this->service->supportEmergencyWorkflowChecklist());

        $this->assertStringContainsString('emergency_support_access_high_risk_approval_not_wired', $joined);
    }
}
