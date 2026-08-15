<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Enums\FirmUserRole;
use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessSessionStatus;
use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use App\Services\FirmSupportAccessService;
use App\Services\SupportAccessPolicyService;
use App\Services\SupportAccessRequestService;
use App\Services\SupportAccessSessionService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 6 — firm consent is the gate ordinary support access cannot get
 * past without the customer. These tests prove the gate is real on the
 * server: who may decide, that a decision cannot cross firms, that a
 * decided/expired request cannot be re-decided, that decisions are
 * audited to the firm user who actually made them, and that a firm can
 * revoke access immediately.
 */
class FirmSupportAccessConsentTest extends TestCase
{
    use RefreshDatabase;

    private FirmSupportAccessService $firmService;

    private SupportAccessRequestService $requestService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firmService = new FirmSupportAccessService;
        $this->requestService = new SupportAccessRequestService;
    }

    private function firmUser(Firm $firm, FirmUserRole $role): FirmUser
    {
        return FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => $role]);
    }

    private function pendingRequest(Firm $firm, ?PlatformAdmin $admin = null): SupportAccessRequest
    {
        return $this->requestService->request(
            $firm,
            $admin ?? PlatformAdmin::factory()->create(),
            SupportAccessType::Standard,
            'Investigating a failing invoice sync reported by the firm.',
            60,
        );
    }

    private function inFirm(Firm $firm, callable $callback): mixed
    {
        return (new TenantContextService)->runWithFirmContext($firm->id, $callback);
    }

    // ---------------------------------------------------------------
    // Who may decide
    // ---------------------------------------------------------------

    public function test_a_firm_owner_can_approve_a_pending_request(): void
    {
        $firm = Firm::factory()->create();
        $request = $this->pendingRequest($firm);

        $approved = $this->firmService->approve($this->firmUser($firm, FirmUserRole::FirmOwner), $request->id);

        $this->assertSame(SupportAccessRequestStatus::Approved, $approved->status);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_a_non_owner_firm_user_cannot_approve(): void
    {
        $firm = Firm::factory()->create();
        $request = $this->pendingRequest($firm);

        foreach ([FirmUserRole::Attorney, FirmUserRole::Paralegal, FirmUserRole::BillingStaff, FirmUserRole::Receptionist, FirmUserRole::LegalAssistant] as $role) {
            try {
                $this->firmService->approve($this->firmUser($firm, $role), $request->id);
                $this->fail("Role {$role->value} must not be able to approve support access.");
            } catch (RuntimeException) {
                // expected
            }
        }

        $this->assertSame(
            SupportAccessRequestStatus::Requested,
            $this->inFirm($firm, fn () => $request->fresh())->status
        );
    }

    public function test_a_non_owner_firm_user_cannot_even_review_pending_requests(): void
    {
        $firm = Firm::factory()->create();
        $this->pendingRequest($firm);

        $this->expectException(RuntimeException::class);

        $this->firmService->pendingRequests($this->firmUser($firm, FirmUserRole::Paralegal));
    }

    // ---------------------------------------------------------------
    // Cross-firm isolation
    // ---------------------------------------------------------------

    public function test_firm_b_owner_cannot_approve_firm_a_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $request = $this->pendingRequest($firmA);

        try {
            $this->firmService->approve($this->firmUser($firmB, FirmUserRole::FirmOwner), $request->id);
            $this->fail('A firm owner must not be able to approve another firm\'s support access request.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(
            SupportAccessRequestStatus::Requested,
            $this->inFirm($firmA, fn () => $request->fresh())->status
        );
    }

    public function test_the_canonical_service_itself_rejects_a_cross_firm_approver(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $request = $this->pendingRequest($firmA);

        $this->expectException(RuntimeException::class);

        // Not through the firm-panel chokepoint — straight at the canonical
        // service, proving the firm match is enforced in the domain and not
        // only at the UI boundary.
        $this->requestService->approve($request, $this->firmUser($firmB, FirmUserRole::FirmOwner));
    }

    public function test_a_firm_only_sees_its_own_pending_requests(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestA = $this->pendingRequest($firmA);
        $this->pendingRequest($firmB);

        $rows = $this->firmService->pendingRequests($this->firmUser($firmA, FirmUserRole::FirmOwner));

        $this->assertCount(1, $rows);
        $this->assertSame($requestA->id, $rows->first()['id']);
    }

    // ---------------------------------------------------------------
    // State machine + idempotency
    // ---------------------------------------------------------------

    public function test_approving_twice_is_idempotent_and_writes_one_audit_row(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->firmUser($firm, FirmUserRole::FirmOwner);
        $request = $this->pendingRequest($firm);

        $first = $this->firmService->approve($owner, $request->id);
        $second = $this->firmService->approve($owner, $request->id);

        $this->assertSame(SupportAccessRequestStatus::Approved, $second->status);
        $this->assertEquals($first->approved_at, $second->approved_at, 'A repeated approval must not rewrite the original decision evidence.');

        $this->assertSame(1, $this->inFirm($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'support_access.request_approved')
            ->count()));
    }

    public function test_a_denied_request_cannot_afterwards_be_approved(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->firmUser($firm, FirmUserRole::FirmOwner);
        $request = $this->pendingRequest($firm);

        $this->firmService->deny($owner, $request->id);

        try {
            $this->firmService->approve($owner, $request->id);
            $this->fail('A denied request must not be approvable.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(
            SupportAccessRequestStatus::Denied,
            $this->inFirm($firm, fn () => $request->fresh())->status
        );
    }

    public function test_an_expired_request_cannot_be_approved(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->firmUser($firm, FirmUserRole::FirmOwner);
        $request = $this->pendingRequest($firm);

        $this->inFirm($firm, fn () => $this->requestService->expire($request));

        $this->expectException(RuntimeException::class);

        $this->firmService->approve($owner, $request->id);
    }

    public function test_a_request_past_its_decision_window_is_rejected_and_reconciled_to_expired(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->firmUser($firm, FirmUserRole::FirmOwner);
        $request = $this->pendingRequest($firm);

        Carbon::setTestNow(now()->addMinutes(SupportAccessRequestService::PENDING_REQUEST_DECISION_WINDOW_MINUTES + 1));

        try {
            $this->firmService->approve($owner, $request->id);
            $this->fail('A request past its decision window must not be approvable.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(
            SupportAccessRequestStatus::Expired,
            $this->inFirm($firm, fn () => $request->fresh())->status,
            'A stale request must be reconciled to Expired as it is refused, not left pending forever.'
        );

        Carbon::setTestNow();
    }

    public function test_a_stale_request_is_not_offered_as_decidable(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->firmUser($firm, FirmUserRole::FirmOwner);
        $this->pendingRequest($firm);

        Carbon::setTestNow(now()->addMinutes(SupportAccessRequestService::PENDING_REQUEST_DECISION_WINDOW_MINUTES + 1));

        $this->assertTrue($this->firmService->pendingRequests($owner)->isEmpty());

        Carbon::setTestNow();
    }

    // ---------------------------------------------------------------
    // Decision evidence + audit attribution
    // ---------------------------------------------------------------

    public function test_an_approval_is_audited_to_the_firm_user_who_actually_approved(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->firmUser($firm, FirmUserRole::FirmOwner);
        $platformAdmin = PlatformAdmin::factory()->create();
        $request = $this->pendingRequest($firm, $platformAdmin);

        $this->firmService->approve($owner, $request->id);

        $event = $this->inFirm($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'support_access.request_approved')
            ->first());

        $this->assertNotNull($event);
        $this->assertSame(FirmUser::class, $event->actor_type, 'The firm\'s own approver performed this action — it must not be attributed to the requesting platform admin.');
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($platformAdmin->id, $event->metadata['requesting_platform_admin_id'] ?? null);
    }

    public function test_a_denial_records_the_denier_and_prevents_a_session(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->firmUser($firm, FirmUserRole::FirmOwner);
        $request = $this->pendingRequest($firm);

        $denied = $this->firmService->deny($owner, $request->id);

        $this->assertSame($owner->id, $denied->denied_by);
        $this->assertNotNull($denied->denied_at);

        $decision = (new SupportAccessPolicyService)->canStartSession($denied);
        $this->assertFalse($decision->allowed, 'A denied request must never authorize a session.');
    }

    // ---------------------------------------------------------------
    // Firm-side revocation
    // ---------------------------------------------------------------

    private function activeSessionFor(Firm $firm): SupportAccessSession
    {
        $owner = $this->firmUser($firm, FirmUserRole::FirmOwner);
        $request = $this->pendingRequest($firm);
        $this->firmService->approve($owner, $request->id);

        return (new SupportAccessSessionService)->start(
            $this->inFirm($firm, fn () => $request->fresh())
        );
    }

    public function test_a_firm_owner_can_revoke_an_active_session_immediately(): void
    {
        $firm = Firm::factory()->create();
        $session = $this->activeSessionFor($firm);
        $owner = $this->firmUser($firm, FirmUserRole::FirmOwner);

        $revoked = $this->firmService->revokeSession($owner, $session->id);

        $this->assertSame(SupportAccessSessionStatus::Revoked, $revoked->status);
        $this->assertFalse($revoked->isCurrentlyValid(), 'A revoked session must stop authorizing access immediately, not at its original expiry.');
        $this->assertNotNull($revoked->revoked_at);
    }

    public function test_firm_revocation_is_audited_to_the_firm_user_not_a_platform_admin(): void
    {
        $firm = Firm::factory()->create();
        $session = $this->activeSessionFor($firm);
        $owner = $this->firmUser($firm, FirmUserRole::FirmOwner);

        $this->firmService->revokeSession($owner, $session->id);

        $event = $this->inFirm($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'support_access.session_revoked_by_firm')
            ->first());

        $this->assertNotNull($event);
        $this->assertSame(FirmUser::class, $event->actor_type);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($session->platform_admin_id, $event->metadata['session_owner_platform_admin_id'] ?? null);
    }

    public function test_firm_b_cannot_revoke_firm_a_session(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $session = $this->activeSessionFor($firmA);

        try {
            $this->firmService->revokeSession($this->firmUser($firmB, FirmUserRole::FirmOwner), $session->id);
            $this->fail('A firm must not be able to revoke another firm\'s support session.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(
            SupportAccessSessionStatus::Active,
            $this->inFirm($firmA, fn () => $session->fresh())->status
        );
    }

    public function test_a_non_owner_cannot_revoke(): void
    {
        $firm = Firm::factory()->create();
        $session = $this->activeSessionFor($firm);

        $this->expectException(RuntimeException::class);

        $this->firmService->revokeSession($this->firmUser($firm, FirmUserRole::Attorney), $session->id);
    }

    public function test_double_revocation_is_safe_and_writes_one_audit_row(): void
    {
        $firm = Firm::factory()->create();
        $session = $this->activeSessionFor($firm);
        $owner = $this->firmUser($firm, FirmUserRole::FirmOwner);

        $first = $this->firmService->revokeSession($owner, $session->id);
        $second = $this->firmService->revokeSession($owner, $session->id);

        $this->assertEquals($first->revoked_at, $second->revoked_at);

        $this->assertSame(1, $this->inFirm($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'support_access.session_revoked_by_firm')
            ->count()));
    }

    public function test_an_expired_but_still_active_flagged_session_is_not_listed_as_active(): void
    {
        $firm = Firm::factory()->create();
        $session = $this->activeSessionFor($firm);
        $owner = $this->firmUser($firm, FirmUserRole::FirmOwner);

        Carbon::setTestNow($session->expires_at->copy()->addSecond());

        $this->assertTrue(
            $this->firmService->activeSessions($owner)->isEmpty(),
            'A session whose expiry has passed no longer authorizes access and must not be presented to the firm as active.'
        );
        $this->assertCount(1, $this->firmService->pastSessions($owner));

        Carbon::setTestNow();
    }
}
