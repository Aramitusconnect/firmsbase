<?php

namespace Tests\Feature\SupportAccess;

use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Services\SupportAccessPolicyService;
use App\Services\SupportAccessRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportAccessPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private SupportAccessPolicyService $policyService;
    private SupportAccessRequestService $requestService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policyService = new SupportAccessPolicyService();
        $this->requestService = new SupportAccessRequestService();
    }

    public function test_standard_request_cannot_start_a_session_until_firm_approved(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $request = $this->requestService->request($firm, $admin, SupportAccessType::Standard, 'reason', 60);

        $decision = $this->policyService->canStartSession($request);

        $this->assertFalse($decision->allowed);
    }

    public function test_standard_request_can_start_a_session_once_firm_approved(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $firmUser = FirmUser::factory()->forFirm($firm)->create();
        $request = $this->requestService->request($firm, $admin, SupportAccessType::Standard, 'reason', 60);
        $this->requestService->approve($request, $firmUser);

        $decision = $this->policyService->canStartSession($request->fresh());

        $this->assertTrue($decision->allowed);
    }

    /**
     * Section 39C: emergency access bypasses FIRM approval (this firm
     * never gets to block/allow it), but it is no longer self-declared
     * only — it still requires platform high-risk approval before a
     * session may start. See
     * tests/Feature/Security/SupportAccess/EmergencySupportHighRiskApprovalTest.php
     * for the full deny-before/allow-after coverage.
     */
    public function test_emergency_request_bypasses_firm_approval_but_still_requires_high_risk_approval(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $request = $this->requestService->request(
            $firm, $admin, SupportAccessType::Emergency, 'production incident', 30, 'Active outage impacting client access'
        );

        // No firm_users approval exists at all for this request, and it
        // is still denied — proving the firm-approval path was indeed
        // bypassed (there is nothing for the firm to approve) while a
        // *different* gate (platform high-risk approval) now applies.
        $decision = $this->policyService->canStartSession($request);
        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('platform high-risk approval', $decision->reason);
    }

    public function test_emergency_access_is_audited_via_security_events(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $request = $this->requestService->request(
            $firm, $admin, SupportAccessType::Emergency, 'production incident', 30, 'Active outage impacting client access'
        );

        $this->policyService->logNotification($request, 'support_access_emergency_granted');

        $this->assertDatabaseHas('security_events', [
            'firm_id' => $firm->id,
            'event_type' => 'support_access_emergency_granted',
            'category' => 'support_access',
        ]);
    }
}
