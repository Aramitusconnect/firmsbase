<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * EnforceSessionTimeouts — Mission 1B (Extreme Security Hardening).
 * Session-management audit finding: this codebase's only idle/absolute
 * session-timeout policy (LoginPolicyService::shouldExpireSession(),
 * SESSION_IDLE_TIMEOUT_MINUTES=30) was pure dead code — no caller
 * anywhere invoked it — so the effective behavior for every panel,
 * including Admin, was the raw `session.lifetime` config value (120
 * minutes), which is purely rolling/idle-based with no absolute
 * ceiling at all: a continuously-active session, on any panel, could
 * persist indefinitely.
 *
 * Two independent, deliberately DIFFERENT-per-panel limits (never one
 * blanket lifetime for every role, per this mission's own instruction):
 *  - idle timeout: no activity for this many minutes -> expired.
 *  - absolute lifetime: this many minutes since first authenticated
 *    request in this session -> expired, regardless of activity.
 *
 * Both are stamped into the session itself (`security_session_login_at`,
 * `security_session_last_activity_at`) rather than derived from the
 * raw session driver's own last-write time, so the check is explicit
 * and independently verifiable rather than piggybacking on
 * `session.lifetime`'s own (looser, rolling-only) semantics.
 *
 * Must run after StartSession (needs a session) and after
 * EstablishPanelAuthGuardDefault (needs the correct guard's user to
 * already resolve via $request->user()).
 */
class EnforceSessionTimeouts
{
    public function handle(Request $request, Closure $next, int $idleTimeoutMinutes, int $absoluteLifetimeMinutes): mixed
    {
        $user = $request->user();

        if (! $request->hasSession() || ! $user) {
            return $next($request);
        }

        $session = $request->session();
        $now = time();

        $loginAt = $session->get('security_session_login_at');
        $lastActivityAt = $session->get('security_session_last_activity_at');

        $idleExpired = $lastActivityAt !== null && ($now - $lastActivityAt) > ($idleTimeoutMinutes * 60);
        $absoluteExpired = $loginAt !== null && ($now - $loginAt) > ($absoluteLifetimeMinutes * 60);

        if ($idleExpired || $absoluteExpired) {
            // Auth::guard() with no argument resolves the panel's own
            // guard here — EstablishPanelAuthGuardDefault (which runs
            // earlier in every panel's middleware stack) has already
            // switched the container's default guard for this request.
            Auth::guard()->logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect()->guest($request->fullUrl());
        }

        if ($loginAt === null) {
            $session->put('security_session_login_at', $now);
        }

        $session->put('security_session_last_activity_at', $now);

        return $next($request);
    }
}
