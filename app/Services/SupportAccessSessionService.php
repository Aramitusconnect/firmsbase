<?php

namespace App\Services;

use App\Enums\SupportAccessSessionStatus;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;

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
 */
class SupportAccessSessionService
{
    public function start(SupportAccessRequest $request): SupportAccessSession
    {
        $expiresAt = now()->addMinutes($request->requested_duration_minutes);

        return (new TenantContextService())->runWithFirmContext($request->firm_id, fn () => SupportAccessSession::create([
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
        return (new TenantContextService())->runWithFirmContext($session->firm_id, function () use ($session) {
            $session->update([
                'status' => SupportAccessSessionStatus::Expired,
                'ended_at' => now(),
            ]);

            return $session->fresh();
        });
    }

    public function revoke(SupportAccessSession $session, \App\Models\PlatformAdmin $revokedBy): SupportAccessSession
    {
        return (new TenantContextService())->runWithFirmContext($session->firm_id, function () use ($session, $revokedBy) {
            $session->update([
                'status' => SupportAccessSessionStatus::Revoked,
                'revoked_by' => $revokedBy->id,
                'revoked_at' => now(),
                'ended_at' => now(),
            ]);

            return $session->fresh();
        });
    }

    public function isValid(SupportAccessSession $session): bool
    {
        return $session->isCurrentlyValid();
    }
}
