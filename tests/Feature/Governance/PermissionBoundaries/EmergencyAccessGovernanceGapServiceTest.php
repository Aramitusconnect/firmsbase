<?php

namespace Tests\Feature\Governance\PermissionBoundaries;

use App\Enums\GovernanceMappingStatus;
use App\Services\EmergencyAccessGovernanceGapService;
use Tests\TestCase;

class EmergencyAccessGovernanceGapServiceTest extends TestCase
{
    private const REQUIRED_CONTROLS = [
        'platform_approval',
        'reason_required',
        'time_limit',
        'automatic_notification',
        'full_audit_trail',
        'high_risk_change_request',
    ];

    private EmergencyAccessGovernanceGapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmergencyAccessGovernanceGapService();
    }

    public function test_required_controls_contains_all_six_expected_controls(): void
    {
        $required = $this->service->requiredControls();

        $this->assertCount(6, $required);

        foreach (self::REQUIRED_CONTROLS as $control) {
            $this->assertContains($control, $required, "Missing required control: {$control}");
        }
    }

    public function test_current_controls_reflects_aws_reality(): void
    {
        $current = $this->service->currentControls();

        $this->assertContains('reason_required', $current);
        $this->assertContains('time_limit', $current);
        $this->assertContains('automatic_notification', $current);
        $this->assertContains('full_audit_trail', $current);
    }

    public function test_missing_controls_includes_platform_approval_and_high_risk_change_request(): void
    {
        $missing = $this->service->missingControls();

        $this->assertContains('platform_approval', $missing);
        $this->assertContains('high_risk_change_request', $missing);
    }

    public function test_missing_and_current_controls_partition_the_required_controls_with_no_overlap(): void
    {
        $current = $this->service->currentControls();
        $missing = $this->service->missingControls();

        $this->assertEmpty(array_intersect($current, $missing));

        sort($current);
        sort($missing);
        $union = array_values(array_unique(array_merge($current, $missing)));
        sort($union);

        $required = self::REQUIRED_CONTROLS;
        sort($required);

        $this->assertSame($required, $union);
    }

    public function test_is_high_risk_approval_wired_is_false(): void
    {
        $this->assertFalse($this->service->isHighRiskApprovalWired());
    }

    public function test_result_status_is_partially_implemented(): void
    {
        $result = $this->service->result();

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $result->status);
        $this->assertNotSame(GovernanceMappingStatus::Implemented, $result->status);
    }

    public function test_result_documents_the_gap_without_fixing_it(): void
    {
        $result = $this->service->result();

        $this->assertStringContainsString('SupportAccessPolicyService', $result->notes);
        $this->assertStringContainsString('never calls it', $result->notes);

        // Confirm the real service was not touched by this test suite.
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- app/Services/SupportAccessPolicyService.php app/Services/SupportAccessRequestService.php'
        ));
        $this->assertSame('', $changed);
    }
}
