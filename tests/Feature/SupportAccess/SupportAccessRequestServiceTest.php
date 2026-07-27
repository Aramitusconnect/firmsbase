<?php

namespace Tests\Feature\SupportAccess;

use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Services\SupportAccessRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportAccessRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private SupportAccessRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SupportAccessRequestService;
    }

    public function test_request_requires_a_non_empty_reason(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->request($firm, $admin, SupportAccessType::Standard, '   ', 60);
    }

    public function test_emergency_request_requires_emergency_justification(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->request($firm, $admin, SupportAccessType::Emergency, 'production incident', 30, null);
    }

    public function test_standard_request_starts_in_requested_status_and_needs_approval(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $request = $this->service->request($firm, $admin, SupportAccessType::Standard, 'Debugging invoice sync', 60);

        $this->assertSame(SupportAccessRequestStatus::Requested, $request->status);
    }

    public function test_approve_and_deny(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $firmUser = FirmUser::factory()->forFirm($firm)->create();

        $requestA = $this->service->request($firm, $admin, SupportAccessType::Standard, 'reason A', 60);
        $approved = $this->service->approve($requestA, $firmUser);
        $this->assertSame(SupportAccessRequestStatus::Approved, $approved->status);
        $this->assertSame($firmUser->id, $approved->approved_by);

        $requestB = $this->service->request($firm, $admin, SupportAccessType::Standard, 'reason B', 60);
        $denied = $this->service->deny($requestB, $firmUser);
        $this->assertSame(SupportAccessRequestStatus::Denied, $denied->status);
    }

    // ------------------------------------------------------------
    // Phase 4 FirmsVault Admin Control Center ("Support" category)
    // additions: expire()'s new $actor + audit plumbing.
    // ------------------------------------------------------------

    public function test_expire_without_an_actor_updates_status_and_writes_no_audit_event(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $request = $this->service->request($firm, $admin, SupportAccessType::Standard, 'stale request', 60);

        $expired = $this->service->expire($request);

        $this->assertSame(SupportAccessRequestStatus::Expired, $expired->status);

        $count = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'support_access_request_expired')
            ->count());
        $this->assertSame(0, $count, 'expire() must stay byte-for-byte unchanged (no audit write) when no actor is supplied.');
    }

    public function test_expire_with_an_actor_updates_status_and_writes_a_firm_scoped_audit_event(): void
    {
        $firm = Firm::factory()->create();
        $requester = PlatformAdmin::factory()->create();
        $actor = PlatformAdmin::factory()->create();
        $request = $this->service->request($firm, $requester, SupportAccessType::Standard, 'stale request', 60);

        $expired = $this->service->expire($request, $actor);

        $this->assertSame(SupportAccessRequestStatus::Expired, $expired->status);

        $audit = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'support_access_request_expired')
            ->first());

        $this->assertNotNull($audit, 'expire() with an actor must write a firm-scoped security_events row.');
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame(PlatformAdmin::class, $audit->actor_type);
        $this->assertSame($request->id, $audit->metadata['support_access_request_id']);
        $this->assertSame('expired', $audit->metadata['resulting_status']);
    }
}
