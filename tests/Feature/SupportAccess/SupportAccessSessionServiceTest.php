<?php

namespace Tests\Feature\SupportAccess;

use App\Enums\SupportAccessSessionStatus;
use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use App\Services\SupportAccessRequestService;
use App\Services\SupportAccessSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportAccessSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    private SupportAccessRequestService $requestService;

    private SupportAccessSessionService $sessionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestService = new SupportAccessRequestService;
        $this->sessionService = new SupportAccessSessionService;
    }

    public function test_starting_a_session_sets_an_expiry_based_on_requested_duration(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $firmUser = FirmUser::factory()->forFirm($firm)->create();

        $request = $this->requestService->request($firm, $admin, SupportAccessType::Standard, 'reason', 45);
        $this->requestService->approve($request, $firmUser);

        $session = $this->sessionService->start($request->fresh());

        $this->assertSame(SupportAccessSessionStatus::Active, $session->status);
        $this->assertTrue($session->expires_at->between(now()->addMinutes(44), now()->addMinutes(46)));
    }

    public function test_expired_sessions_do_not_authorize_access(): void
    {
        $firm = Firm::factory()->create();
        // support_access_sessions now carries a real composite foreign
        // key, (firm_id, support_access_request_id) REFERENCES
        // support_access_requests(firm_id, id) — overriding only
        // firm_id here (as this test previously did) while leaving the
        // factory's own independently-generated parent
        // support_access_request tied to a DIFFERENT firm now correctly
        // fails at the database layer. An explicit, matching parent
        // request for THIS firm must be created first.
        $request = SupportAccessRequest::factory()->forFirm($firm)->create();
        $session = SupportAccessSession::factory()->expired()->create([
            'firm_id' => $firm->id,
            'support_access_request_id' => $request->id,
        ]);

        $this->assertFalse($this->sessionService->isValid($session));
    }

    public function test_end_and_revoke_mark_a_session_invalid(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $firmUser = FirmUser::factory()->forFirm($firm)->create();
        $request = $this->requestService->request($firm, $admin, SupportAccessType::Standard, 'reason', 60);
        $this->requestService->approve($request, $firmUser);
        $session = $this->sessionService->start($request->fresh());

        $ended = $this->sessionService->end($session);
        $this->assertFalse($this->sessionService->isValid($ended));

        $session2 = $this->sessionService->start($request->fresh());
        $revoked = $this->sessionService->revoke($session2, $admin);
        $this->assertFalse($this->sessionService->isValid($revoked));
        $this->assertSame($admin->id, $revoked->revoked_by);
    }
}
