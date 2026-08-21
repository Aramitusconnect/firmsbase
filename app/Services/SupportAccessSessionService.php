<?php

namespace App\Services;

use App\Enums\SupportAccessSessionStatus;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use Illuminate\Support\Facades\DB;

/**
 * SupportAccessSessionService — the only writer of
 * support_access_sessions. A session can only be started from a
 * SupportAccessRequest that SupportAccessPolicyService has confirmed is
 * allowed (approved, or emergency) — this service does not itself
 * re-check firm approval, it trusts the request's own status. Expired
 * sessions must never authorize access: expire()/isValid() are the two
 * places that enforce this, and SupportAccessSession::isCurrentlyValid()
 * independently re-checks expires_at rather than trusting the status
 * column alone.
 *
 * Scope (Non-Payment Completion Program, Workstream 8 — confirmed,
 * documented decision, not an oversight): today the only production
 * consumer that gates read/mutation access behind an active session
 * from this service is
 * PlatformFirmIntegrationBoundedAccessService::assertCanAccessFirm(),
 * scoping this mechanism to the Integration Platform Oversight
 * surface (PlatformFirmIntegrationsPage /
 * PlatformFirmIntegrationDetailPage). This is intentional, not a
 * partial rollout — every other platform-admin Filament resource that
 * reads firm-scoped data (ConflictResource, MigrationProjectResource,
 * NotificationTemplateResource, AuditLogResource, and siblings)
 * enforces its own independent, role-based
 * PlatformStaffAccessPolicyService gate and does not consult this
 * session mechanism, matching those resources' own documented
 * architecture (see e.g. FirmResource's own docblock). A future
 * surface that legitimately needs governed, time-boxed support access
 * should route through this same service rather than inventing a
 * parallel mechanism — but expanding its scope beyond Integration
 * Oversight is a deliberate, separate decision, not something this
 * service's mere existence implies.
 */
class SupportAccessSessionService
{
    public function start(SupportAccessRequest $request): SupportAccessSession
    {
        $expiresAt = now()->addMinutes($request->requested_duration_minutes);

        return (new TenantContextService)->runWithFirmContext($request->firm_id, fn () => SupportAccessSession::create([
            'support_access_request_id' => $request->id,
            'firm_id' => $request->firm_id,
            'platform_admin_id' => $request->requested_by,
            'status' => SupportAccessSessionStatus::Active,
            'started_at' => now(),
            'expires_at' => $expiresAt,
        ]));
    }

    public function end(SupportAccessSession $session): SupportAccessSession
    {
        return (new TenantContextService)->runWithFirmContext($session->firm_id, function () use ($session) {
            $session->update([
                'status' => SupportAccessSessionStatus::Expired,
                'ended_at' => now(),
            ]);

            return $session->fresh();
        });
    }

    public function revoke(SupportAccessSession $session, PlatformAdmin $revokedBy): SupportAccessSession
    {
        return (new TenantContextService)->runWithFirmContext($session->firm_id, function () use ($session, $revokedBy) {
            $session->update([
                'status' => SupportAccessSessionStatus::Revoked,
                'revoked_by' => $revokedBy->id,
                'revoked_at' => now(),
                'ended_at' => now(),
            ]);

            return $session->fresh();
        });
    }

    /**
     * Firm-side revocation — the customer's own right to end a support
     * session into their firm immediately, without waiting for it to
     * expire and without needing platform staff to act.
     *
     * Prompt 6 addition. revoke() above takes a PlatformAdmin because
     * support_access_sessions.revoked_by is a foreign key to
     * platform_admins; there is no column on this table that can hold a
     * FirmUser id, and Prompt 6 introduces no schema change. Rather than
     * misattribute the firm's action to some platform admin — the exact
     * class of false attribution this mission fixed in
     * SupportAccessPolicyService::logSessionAudit() — revoked_by is left
     * NULL and the real acting FirmUser is recorded in the security_events
     * row below. That is the same honest-null convention
     * PlatformFirmIntegrationBoundedAccessService already established in
     * this domain when passing actorFirmUserId: null into services that
     * cannot represent a PlatformAdmin actor. The security-critical
     * property — the session stops authorizing access immediately — is
     * fully delivered; only the native-column attribution is deferred to
     * an owner-approved schema change (see the Prompt 6 report's database
     * stop gate).
     *
     * Authorization, state validation, locking, idempotency and audit are
     * all enforced here, in the canonical writer, because unlike the
     * platform path there is no separate chokepoint class in front of the
     * firm panel.
     *
     * @throws \RuntimeException when the revoker belongs to a different firm.
     */
    public function revokeByFirm(SupportAccessSession $session, FirmUser $revoker): SupportAccessSession
    {
        if ((int) $revoker->firm_id !== (int) $session->firm_id) {
            throw new \RuntimeException('A support access session may only be revoked by a user of the firm it targets.');
        }

        return (new TenantContextService)->runWithFirmContext($session->firm_id, function () use ($session, $revoker) {
            return DB::transaction(function () use ($session, $revoker) {
                $fresh = SupportAccessSession::query()
                    ->where('id', $session->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Idempotent: an already-terminal session (revoked by
                // either side, ended, or expired) is returned untouched,
                // with no duplicate audit row. Double-revoke, and
                // firm-revoke racing platform-terminate, both converge on
                // one canonical terminal state.
                if ($fresh->status !== SupportAccessSessionStatus::Active) {
                    return $fresh;
                }

                $fresh->update([
                    'status' => SupportAccessSessionStatus::Revoked,
                    'revoked_at' => now(),
                    'ended_at' => now(),
                ]);

                $revoked = $fresh->fresh();

                DB::table('security_events')->insert([
                    'firm_id' => $revoked->firm_id,
                    'actor_type' => FirmUser::class,
                    'actor_id' => $revoker->id,
                    'event_type' => 'support_access.session_revoked_by_firm',
                    'category' => 'support_access',
                    'metadata' => json_encode([
                        'support_access_session_id' => $revoked->id,
                        'support_access_session_uuid' => $revoked->uuid,
                        'support_access_request_id' => $revoked->support_access_request_id,
                        'session_owner_platform_admin_id' => $revoked->platform_admin_id,
                        'revoked_by_firm_user_id' => $revoker->id,
                    ]),
                    'created_at' => now(),
                ]);

                return $revoked;
            });
        });
    }

    public function isValid(SupportAccessSession $session): bool
    {
        return $session->isCurrentlyValid();
    }
}
