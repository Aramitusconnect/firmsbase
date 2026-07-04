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

    public function test_emergency_request_bypasses_firm_approval(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $request = $this->requestService->request(
            $firm, $admin, SupportAccessType::Emergency, 'production incident', 30, 'Active outage impacting client access'
        );

        $decision = $this->policyService->canStartSession($request);

        $this->assertTrue($decision->allowed);
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
