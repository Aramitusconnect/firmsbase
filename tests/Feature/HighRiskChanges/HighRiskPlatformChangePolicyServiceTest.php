<?php

namespace Tests\Feature\HighRiskChanges;

use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Models\PlatformAdmin;
use App\Services\HighRiskPlatformChangePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HighRiskPlatformChangePolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private HighRiskPlatformChangePolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HighRiskPlatformChangePolicyService();
    }

    public function test_request_requires_a_reason(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->request(HighRiskChangeType::TrustModeActivation, $admin, '   ');
    }

    public function test_trust_mode_activation_requires_two_person_approval(): void
    {
        $requester = PlatformAdmin::factory()->create();
        $firstApprover = PlatformAdmin::factory()->create();
        $secondApprover = PlatformAdmin::factory()->create();

        $request = $this->service->request(HighRiskChangeType::TrustModeActivation, $requester, 'Firm completed trust onboarding');

        $firstDecision = $this->service->firstApprove($request, $firstApprover);
        $this->assertSame(HighRiskChangeRequestStatus::FirstApproved, $firstDecision->status);
        $this->assertTrue($firstDecision->requiresSecondApproval);

        $secondDecision = $this->service->secondApprove($request->fresh(), $secondApprover);
        $this->assertSame(HighRiskChangeRequestStatus::Approved, $secondDecision->status);
    }

    public function test_the_same_admin_cannot_provide_both_approvals(): void
    {
        $requester = PlatformAdmin::factory()->create();
        $approver = PlatformAdmin::factory()->create();

        $request = $this->service->request(HighRiskChangeType::ProductionDataDeletion, $requester, 'Client requested full data purge');
        $this->service->firstApprove($request, $approver);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->secondApprove($request->fresh(), $approver);
    }

    public function test_emergency_support_access_change_type_does_not_require_second_approval(): void
    {
        $requester = PlatformAdmin::factory()->create();
        $approver = PlatformAdmin::factory()->create();

        $request = $this->service->request(HighRiskChangeType::EmergencySupportAccess, $requester, 'Outage response');
        $decision = $this->service->firstApprove($request, $approver);

        $this->assertSame(HighRiskChangeRequestStatus::Approved, $decision->status);
        $this->assertFalse($decision->requiresSecondApproval);
    }

    public function test_deny_and_cancel(): void
    {
        $requester = PlatformAdmin::factory()->create();
        $denier = PlatformAdmin::factory()->create();

        $requestA = $this->service->request(HighRiskChangeType::PaymentTrustSettingChange, $requester, 'reason A');
        $denied = $this->service->deny($requestA, $denier, 'Insufficient justification');
        $this->assertSame(HighRiskChangeRequestStatus::Denied, $denied->status);

        $requestB = $this->service->request(HighRiskChangeType::PaymentTrustSettingChange, $requester, 'reason B');
        $cancelled = $this->service->cancel($requestB);
        $this->assertSame(HighRiskChangeRequestStatus::Cancelled, $cancelled->status);
    }

    public function test_no_executed_status_exists(): void
    {
        $cases = array_map(fn (HighRiskChangeRequestStatus $s) => $s->value, HighRiskChangeRequestStatus::cases());

        $this->assertNotContains('executed', $cases);
    }
}
