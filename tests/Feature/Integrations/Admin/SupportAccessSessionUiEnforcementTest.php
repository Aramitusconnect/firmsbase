<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessSessionStatus;
use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * SupportAccessSessionUiEnforcementTest — Checkpoint 11 (frozen-design-
 * post-security-review.md §8). Proves all four support-access
 * governance-gap closures implemented in
 * PlatformFirmIntegrationBoundedAccessService — the first real caller of
 * SupportAccessRequestService/SupportAccessSessionService/
 * SupportAccessPolicyService.
 */
final class SupportAccessSessionUiEnforcementTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // Gap closure #1: request() -> logNotification() as two explicit
    // sequential calls.
    // ------------------------------------------------------------

    public function test_requesting_support_access_logs_a_security_event_notification(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $request = app(PlatformFirmIntegrationBoundedAccessService::class)->requestSupportAccess(
            $admin,
            $firm,
            SupportAccessType::Standard,
            'Investigating a failed sync for this firm.',
            60,
        );

        $this->assertInstanceOf(SupportAccessRequest::class, $request);

        $event = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'support_access.requested')
                ->where('category', 'support_access')
                ->first()
        );

        $this->assertNotNull($event, 'Expected a support_access.requested SecurityEvent row.');
        $this->assertSame(\App\Models\PlatformAdmin::class, $event->actor_type);
        $this->assertSame($admin->id, $event->actor_id);
    }

    // ------------------------------------------------------------
    // Gap closure #2: requester-identity mismatch on session-start is
    // denied.
    // ------------------------------------------------------------

    public function test_entering_a_session_for_a_request_made_by_a_different_admin_is_denied(): void
    {
        $firm = Firm::factory()->activated()->create();
        $requester = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $impersonator = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $request = $this->runWithFirmContext(
            $firm,
            fn () => SupportAccessRequest::factory()->forFirm($firm)->create([
                'requested_by' => $requester->id,
                'status' => SupportAccessRequestStatus::Approved->value,
                'approved_at' => now(),
            ])
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only the platform admin who requested support access may start this session.');

        app(PlatformFirmIntegrationBoundedAccessService::class)->enterSupportAccessSession($impersonator, $request);
    }

    public function test_entering_a_session_for_a_request_made_by_a_different_admin_creates_no_session_row(): void
    {
        $firm = Firm::factory()->activated()->create();
        $requester = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $impersonator = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $request = $this->runWithFirmContext(
            $firm,
            fn () => SupportAccessRequest::factory()->forFirm($firm)->create([
                'requested_by' => $requester->id,
                'status' => SupportAccessRequestStatus::Approved->value,
                'approved_at' => now(),
            ])
        );

        try {
            app(PlatformFirmIntegrationBoundedAccessService::class)->enterSupportAccessSession($impersonator, $request);
        } catch (RuntimeException) {
            // expected
        }

        $count = $this->runWithFirmContext(
            $firm,
            fn () => SupportAccessSession::query()->where('support_access_request_id', $request->id)->count()
        );

        $this->assertSame(0, $count);
    }

    // ------------------------------------------------------------
    // Gap closure #4: canStartSession() denial blocks start().
    // ------------------------------------------------------------

    public function test_a_standard_request_not_yet_approved_by_the_firm_blocks_start(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $request = $this->runWithFirmContext(
            $firm,
            fn () => SupportAccessRequest::factory()->forFirm($firm)->create([
                'requested_by' => $admin->id,
                'status' => SupportAccessRequestStatus::Requested->value,
            ])
        );

        $this->expectException(RuntimeException::class);

        app(PlatformFirmIntegrationBoundedAccessService::class)->enterSupportAccessSession($admin, $request);
    }

    public function test_an_approved_request_by_the_correct_requester_successfully_starts_a_session(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $request = $this->runWithFirmContext(
            $firm,
            fn () => SupportAccessRequest::factory()->forFirm($firm)->create([
                'requested_by' => $admin->id,
                'status' => SupportAccessRequestStatus::Approved->value,
                'approved_at' => now(),
            ])
        );

        $session = app(PlatformFirmIntegrationBoundedAccessService::class)->enterSupportAccessSession($admin, $request);

        $this->assertSame(SupportAccessSessionStatus::Active, $session->status);

        $event = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'support_access.session_started')
                ->first()
        );
        $this->assertNotNull($event);
    }

    // ------------------------------------------------------------
    // Gap closure #3: end()/revoke() idempotency.
    // ------------------------------------------------------------

    public function test_leaving_an_already_ended_session_no_ops_and_does_not_double_audit(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $session = $this->activeSessionFor($admin, $firm);

        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $first = $bounded->leaveSupportAccessSession($admin, $session);
        $this->assertSame(SupportAccessSessionStatus::Expired, $first->status);

        // Second call on an already-terminal session must no-op — not
        // throw, not double-audit.
        $second = $bounded->leaveSupportAccessSession($admin, $this->runWithFirmContext($firm, fn () => $session->fresh()));
        $this->assertSame(SupportAccessSessionStatus::Expired, $second->status);

        $auditCount = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'support_access.session_ended')
                ->count()
        );

        $this->assertSame(1, $auditCount, 'A second leave() call on an already-terminal session must not write a second audit row.');
    }

    public function test_revoking_an_already_revoked_session_no_ops_and_does_not_double_audit(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $revoker = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $session = $this->activeSessionFor($admin, $firm);

        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $first = $bounded->revokeSupportAccessSession($revoker, $session);
        $this->assertSame(SupportAccessSessionStatus::Revoked, $first->status);

        $second = $bounded->revokeSupportAccessSession($revoker, $this->runWithFirmContext($firm, fn () => $session->fresh()));
        $this->assertSame(SupportAccessSessionStatus::Revoked, $second->status);

        $auditCount = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'support_access.session_revoked')
                ->count()
        );

        $this->assertSame(1, $auditCount);
    }

    public function test_revoking_an_already_ended_session_no_ops_too(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $revoker = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $session = $this->activeSessionFor($admin, $firm);

        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $bounded->leaveSupportAccessSession($admin, $session);
        $result = $bounded->revokeSupportAccessSession($revoker, $this->runWithFirmContext($firm, fn () => $session->fresh()));

        // Already Expired (not Active) -> revoke() must no-op, leaving
        // the session Expired rather than flipping it to Revoked.
        $this->assertSame(SupportAccessSessionStatus::Expired, $result->status);
    }

    // ------------------------------------------------------------
    // Security review Finding 1: leaveSupportAccessSession() enforces
    // session ownership itself, not merely via the Filament UI's
    // own-sessions-only Select ->options() constraint.
    // ------------------------------------------------------------

    public function test_leaving_a_session_owned_by_a_different_admin_is_denied(): void
    {
        $firm = Firm::factory()->activated()->create();
        $sessionOwner = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $otherAdmin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $session = $this->activeSessionFor($sessionOwner, $firm);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only the platform admin who holds this support access session may leave it.');

        app(PlatformFirmIntegrationBoundedAccessService::class)->leaveSupportAccessSession($otherAdmin, $session);
    }

    public function test_leaving_a_session_owned_by_a_different_admin_leaves_it_untouched(): void
    {
        $firm = Firm::factory()->activated()->create();
        $sessionOwner = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $otherAdmin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $session = $this->activeSessionFor($sessionOwner, $firm);

        try {
            app(PlatformFirmIntegrationBoundedAccessService::class)->leaveSupportAccessSession($otherAdmin, $session);
        } catch (RuntimeException) {
            // expected
        }

        $fresh = $this->runWithFirmContext($firm, fn () => $session->fresh());

        $this->assertSame(SupportAccessSessionStatus::Active, $fresh->status);

        $auditCount = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->whereIn('event_type', ['support_access.session_ended', 'platform_integration_oversight.support_access_session_ended'])
                ->count()
        );

        $this->assertSame(0, $auditCount, 'A denied cross-actor leave attempt must write no audit row at all.');
    }

    // ------------------------------------------------------------
    // Security review Finding 2: revoking a DIFFERENT admin's session
    // writes a companion security_events row correctly attributed to the
    // REVOKER, not the session owner (SupportAccessPolicyService::
    // logSessionAudit() — frozen — always attributes actor_id to the
    // session owner).
    // ------------------------------------------------------------

    public function test_a_cross_actor_revoke_writes_a_security_event_attributed_to_the_revoker_not_the_session_owner(): void
    {
        $firm = Firm::factory()->activated()->create();
        $sessionOwner = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $revoker = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $session = $this->activeSessionFor($sessionOwner, $firm);

        $this->assertNotSame($sessionOwner->id, $revoker->id);

        $revoked = app(PlatformFirmIntegrationBoundedAccessService::class)->revokeSupportAccessSession($revoker, $session);

        $this->assertSame(SupportAccessSessionStatus::Revoked, $revoked->status);

        // The pre-existing support_access-category row still misattributes
        // to the session owner (SupportAccessPolicyService::
        // logSessionAudit() is frozen, unmodified) — that row is not what
        // this test proves.
        $legacyEvent = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'support_access.session_revoked')
                ->where('category', 'support_access')
                ->first()
        );
        $this->assertNotNull($legacyEvent);
        $this->assertSame($sessionOwner->id, $legacyEvent->actor_id);

        // The NEW, correctly-attributed companion row this fix adds —
        // actor_id must be the REVOKER, never the session owner.
        $oversightEvent = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'platform_integration_oversight.support_access_session_revoked')
                ->where('category', 'platform_integration_oversight')
                ->first()
        );

        $this->assertNotNull($oversightEvent, 'Expected a correctly-attributed platform_integration_oversight.support_access_session_revoked SecurityEvent row.');
        $this->assertSame(\App\Models\PlatformAdmin::class, $oversightEvent->actor_type);
        $this->assertSame($revoker->id, $oversightEvent->actor_id);
        $this->assertNotSame($sessionOwner->id, $oversightEvent->actor_id);
    }

    public function test_a_self_service_leave_also_writes_a_correctly_attributed_companion_security_event(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $session = $this->activeSessionFor($admin, $firm);

        $ended = app(PlatformFirmIntegrationBoundedAccessService::class)->leaveSupportAccessSession($admin, $session);

        $this->assertSame(SupportAccessSessionStatus::Expired, $ended->status);

        $oversightEvent = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'platform_integration_oversight.support_access_session_ended')
                ->where('category', 'platform_integration_oversight')
                ->first()
        );

        $this->assertNotNull($oversightEvent);
        $this->assertSame($admin->id, $oversightEvent->actor_id);
    }

    // ------------------------------------------------------------
    // Expired/wrong-firm sessions correctly denied for drill-down.
    // ------------------------------------------------------------

    public function test_an_expired_session_does_not_grant_drill_down_access(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $request = $this->runWithFirmContext($firm, fn () => SupportAccessRequest::factory()->forFirm($firm)->create(['requested_by' => $admin->id]));
        $this->runWithFirmContext($firm, fn () => SupportAccessSession::factory()->expired()->create([
            'firm_id' => $firm->id,
            'support_access_request_id' => $request->id,
            'platform_admin_id' => $admin->id,
        ]));

        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $this->assertFalse($bounded->hasActiveSupportAccessSessionFor($admin, $firm));

        $this->expectException(RuntimeException::class);
        $bounded->assertCanAccessFirm($admin, $firm);
    }

    public function test_a_session_for_a_different_firm_does_not_grant_drill_down_access_to_the_target_firm(): void
    {
        $targetFirm = Firm::factory()->activated()->create();
        $otherFirm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $this->activeSessionFor($admin, $otherFirm);

        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $this->assertFalse($bounded->hasActiveSupportAccessSessionFor($admin, $targetFirm));

        $this->expectException(RuntimeException::class);
        $bounded->assertCanAccessFirm($admin, $targetFirm);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function activeSessionFor(PlatformAdmin $admin, Firm $firm): SupportAccessSession
    {
        $request = $this->runWithFirmContext(
            $firm,
            fn () => SupportAccessRequest::factory()->forFirm($firm)->create(['requested_by' => $admin->id])
        );

        return $this->runWithFirmContext(
            $firm,
            fn () => SupportAccessSession::factory()->create([
                'firm_id' => $firm->id,
                'support_access_request_id' => $request->id,
                'platform_admin_id' => $admin->id,
                'status' => SupportAccessSessionStatus::Active->value,
                'started_at' => now(),
                'expires_at' => now()->addHour(),
            ])
        );
    }
}
