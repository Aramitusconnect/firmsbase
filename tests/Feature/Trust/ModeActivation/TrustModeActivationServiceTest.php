<?php

namespace Tests\Feature\Trust\ModeActivation;

use App\Enums\FirmUserRole;
use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Enums\TrustApprovalEventType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Services\TrustModeActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves Phase 13's trust-mode activation is a thin, non-duplicating
 * wrapper around the EXISTING Phase 7 two-person HighRiskChangeRequest
 * flow (project rule: no second approval mechanism). No automatic or
 * one-person activation path exists anywhere in this service.
 */
class TrustModeActivationServiceTest extends TestCase
{
    use RefreshDatabase;

    private TrustModeActivationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustModeActivationService::class);
    }

    public function test_requesting_activation_creates_a_trust_mode_activation_high_risk_change_request(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $request = $this->service->requestActivation($firm, $admin, 'Pilot activation.');

        $this->assertSame(HighRiskChangeType::TrustModeActivation, $request->change_type);
        $this->assertSame(HighRiskChangeRequestStatus::Pending, $request->status);
        $this->assertSame($firm->id, (int) $request->metadata['firm_id']);
    }

    public function test_second_approval_by_the_same_admin_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $request = $this->service->requestActivation($firm, $admin, 'Pilot activation.');
        $this->service->firstApprove($request, $admin);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->secondApprove($request->fresh(), $admin);
    }

    public function test_linking_requires_the_request_to_be_fully_approved(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $recordedBy = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $request = $this->service->requestActivation($firm, $admin, 'Pilot activation.');
        $this->service->firstApprove($request, $admin);

        $this->expectException(\RuntimeException::class);
        $this->service->linkApprovedActivation($firm, $request->fresh(), $recordedBy);
    }

    public function test_linking_requires_the_request_to_have_been_raised_for_this_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $firstAdmin = PlatformAdmin::factory()->create();
        $secondAdmin = PlatformAdmin::factory()->create();
        $recordedBy = FirmUser::factory()->create(['firm_id' => $otherFirm->id, 'role' => FirmUserRole::FirmOwner]);

        $request = $this->service->requestActivation($firm, $firstAdmin, 'Pilot activation.');
        $this->service->firstApprove($request, $firstAdmin);
        $this->service->secondApprove($request->fresh(), $secondAdmin);

        $this->expectException(\RuntimeException::class);
        $this->service->linkApprovedActivation($otherFirm, $request->fresh(), $recordedBy);
    }

    public function test_full_two_person_flow_links_exactly_one_trust_mode_activation_linked_event(): void
    {
        $firm = Firm::factory()->create();
        $firstAdmin = PlatformAdmin::factory()->create();
        $secondAdmin = PlatformAdmin::factory()->create();
        $recordedBy = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $request = $this->service->requestActivation($firm, $firstAdmin, 'Pilot activation.');
        $this->service->firstApprove($request, $firstAdmin);
        $this->service->secondApprove($request->fresh(), $secondAdmin);
        $event = $this->service->linkApprovedActivation($firm, $request->fresh(), $recordedBy);

        $this->assertSame(TrustApprovalEventType::TrustModeActivationLinked, $event->event_type);
        $this->assertDatabaseCount('trust_approval_events', 1);
    }
}
