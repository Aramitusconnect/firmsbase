<?php

namespace Tests\Feature\Security\SupportAccess;

use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\HighRiskChangeRequest;
use App\Models\PlatformAdmin;
use App\Services\HighRiskPlatformChangePolicyService;
use App\Services\SupportAccessPolicyService;
use App\Services\SupportAccessRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EmergencySupportHighRiskApprovalTest — Section 39C. Proves emergency
 * support access is no longer self-declared-only: it requires the
 * SAME existing HighRiskPlatformChangePolicyService/HighRiskChangeType
 * two-person-approval-ready workflow already used by
 * trust_mode_activation and the other existing high-risk change
 * types, reused exactly as-is (no second approval/audit system).
 */
class EmergencySupportHighRiskApprovalTest extends TestCase
{
    use RefreshDatabase;

    private SupportAccessRequestService $requestService;

    private SupportAccessPolicyService $policyService;

    private HighRiskPlatformChangePolicyService $highRiskPolicy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestService = new SupportAccessRequestService();
        $this->policyService = new SupportAccessPolicyService();
        $this->highRiskPolicy = new HighRiskPlatformChangePolicyService();
    }

    public function test_emergency_request_raises_a_high_risk_change_request_of_the_correct_type(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $request = $this->requestService->request(
            $firm, $admin, SupportAccessType::Emergency, 'production incident', 30, 'Active outage impacting client access'
        );

        $highRiskRequest = HighRiskChangeRequest::query()
            ->where('change_type', HighRiskChangeType::EmergencySupportAccess->value)
            ->latest()
            ->first();

        $this->assertNotNull($highRiskRequest, 'Expected a high_risk_change_requests row to be raised for the emergency request.');
        $this->assertSame(HighRiskChangeType::EmergencySupportAccess, $highRiskRequest->change_type);
        $this->assertSame(HighRiskChangeRequestStatus::Pending, $highRiskRequest->status);
        $this->assertSame($request->id, (int) $highRiskRequest->metadata['support_access_request_id']);
        $this->assertSame($admin->id, $highRiskRequest->requested_by);
    }

    public function test_emergency_session_cannot_become_active_usable_without_high_risk_approval(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $request = $this->requestService->request(
            $firm, $admin, SupportAccessType::Emergency, 'production incident', 30, 'Active outage impacting client access'
        );

        $decision = $this->policyService->canStartSession($request);

        $this->assertFalse($decision->allowed, 'Emergency access must not be usable before high-risk approval.');
        $this->assertStringContainsString('platform high-risk approval', $decision->reason);
        $this->assertFalse($this->requestService->isEmergencyHighRiskApproved($request));
    }

    public function test_emergency_session_becomes_usable_once_high_risk_approval_is_granted(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $approvingAdmin = PlatformAdmin::factory()->create();

        $request = $this->requestService->request(
            $firm, $admin, SupportAccessType::Emergency, 'production incident', 30, 'Active outage impacting client access'
        );

        $highRiskRequest = HighRiskChangeRequest::query()
            ->where('change_type', HighRiskChangeType::EmergencySupportAccess->value)
            ->latest()
            ->first();

        // EmergencySupportAccess never requires a second approval —
        // firstApprove() alone reaches Approved for this change type
        // (HighRiskChangeRequest::requiresSecondApproval() is false).
        $this->assertFalse($highRiskRequest->requiresSecondApproval());

        $decision = $this->highRiskPolicy->firstApprove($highRiskRequest, $approvingAdmin);
        $this->assertSame(HighRiskChangeRequestStatus::Approved, $decision->status);

        $this->assertTrue($this->requestService->isEmergencyHighRiskApproved($request));

        $finalDecision = $this->policyService->canStartSession($request->fresh());
        $this->assertTrue($finalDecision->allowed, 'Emergency access should be usable once high-risk approval is Approved.');
    }

    public function test_emergency_request_still_requires_a_non_empty_reason(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->requestService->request($firm, $admin, SupportAccessType::Emergency, '   ', 30, 'Active outage impacting client access');
    }

    public function test_emergency_request_still_requires_emergency_justification(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->requestService->request($firm, $admin, SupportAccessType::Emergency, 'production incident', 30, null);
    }

    public function test_emergency_request_still_carries_a_required_duration_timebox(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $request = $this->requestService->request(
            $firm, $admin, SupportAccessType::Emergency, 'production incident', 45, 'Active outage impacting client access'
        );

        $this->assertSame(45, $request->requested_duration_minutes);
    }

    public function test_non_emergency_standard_support_access_behavior_is_not_broken(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $firmUser = FirmUser::factory()->forFirm($firm)->create();

        $request = $this->requestService->request($firm, $admin, SupportAccessType::Standard, 'Debugging invoice sync', 60);

        $this->assertSame(SupportAccessRequestStatus::Requested, $request->status);

        $deniedBeforeApproval = $this->policyService->canStartSession($request);
        $this->assertFalse($deniedBeforeApproval->allowed);

        $this->requestService->approve($request, $firmUser);

        $allowedAfterApproval = $this->policyService->canStartSession($request->fresh());
        $this->assertTrue($allowedAfterApproval->allowed);

        // Standard access must never raise an EmergencySupportAccess
        // high-risk change request.
        $this->assertNull(
            HighRiskChangeRequest::query()
                ->where('change_type', HighRiskChangeType::EmergencySupportAccess->value)
                ->get()
                ->first(fn (HighRiskChangeRequest $r) => (int) ($r->metadata['support_access_request_id'] ?? 0) === $request->id)
        );
    }

    public function test_emergency_high_risk_request_uses_the_existing_high_risk_change_type_enum_case(): void
    {
        $this->assertContains(HighRiskChangeType::EmergencySupportAccess, HighRiskChangeType::cases());
        $this->assertSame('emergency_support_access', HighRiskChangeType::EmergencySupportAccess->value);
    }

    public function test_emergency_high_risk_approval_is_audited_via_the_existing_security_events_table(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $approvingAdmin = PlatformAdmin::factory()->create();

        $this->requestService->request(
            $firm, $admin, SupportAccessType::Emergency, 'production incident', 30, 'Active outage impacting client access'
        );

        $highRiskRequest = HighRiskChangeRequest::query()
            ->where('change_type', HighRiskChangeType::EmergencySupportAccess->value)
            ->latest()
            ->first();

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'high_risk_change_requested',
            'category' => 'high_risk_change',
        ]);

        $this->highRiskPolicy->firstApprove($highRiskRequest, $approvingAdmin);

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'high_risk_change_first_approved',
            'category' => 'high_risk_change',
        ]);
    }
}
