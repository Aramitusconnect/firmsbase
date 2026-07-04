<?php

namespace Tests\Feature\SupportAccess;

use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
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
        $this->service = new SupportAccessRequestService();
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
}
