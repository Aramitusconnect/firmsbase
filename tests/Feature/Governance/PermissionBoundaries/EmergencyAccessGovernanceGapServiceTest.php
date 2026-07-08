<?php

namespace Tests\Feature\Governance\PermissionBoundaries;

use App\Enums\GovernanceMappingStatus;
use App\Services\EmergencyAccessGovernanceGapService;
use Tests\TestCase;

/**
 * EmergencyAccessGovernanceGapServiceTest — updated by Section 39C to
 * reflect that emergency support access high-risk approval is now
 * wired (SupportAccessRequestService + SupportAccessPolicyService).
 * This does not hide the original Section 27 finding — it records
 * that the finding was real and has now been remediated, while the
 * ComplianceGapRegistryService entry itself remains open/untouched
 * (see the service's docblock) because no resolved-state lifecycle
 * exists on GapRegisterItem yet.
 */
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

    public function test_current_controls_now_includes_all_six_controls_after_section_39c(): void
    {
        $current = $this->service->currentControls();

        foreach (self::REQUIRED_CONTROLS as $control) {
            $this->assertContains($control, $current, "Control '{$control}' should now be current after Section 39C wiring.");
        }
    }

    public function test_missing_controls_is_now_empty_after_section_39c(): void
    {
        $this->assertEmpty($this->service->missingControls());
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

    public function test_is_high_risk_approval_wired_is_now_true(): void
    {
        $this->assertTrue($this->service->isHighRiskApprovalWired());
    }

    public function test_result_status_is_now_implemented(): void
    {
        $result = $this->service->result();

        $this->assertSame(GovernanceMappingStatus::Implemented, $result->status);
        $this->assertNotSame(GovernanceMappingStatus::PartiallyImplemented, $result->status);
    }

    public function test_result_documents_the_fix_and_the_reused_high_risk_system(): void
    {
        $result = $this->service->result();

        $this->assertStringContainsString('SupportAccessPolicyService', $result->notes);
        $this->assertStringContainsString('isEmergencyHighRiskApproved', $result->notes);
        $this->assertStringContainsString('No second approval/audit system was introduced', $result->notes);
    }

    public function test_result_transparently_notes_the_gap_registry_entry_remains_open(): void
    {
        $result = $this->service->result();

        $this->assertStringContainsString('emergency_support_access_high_risk_approval_not_wired', $result->notes);
        $this->assertStringContainsString('remains open/untouched', $result->notes);
    }
}
