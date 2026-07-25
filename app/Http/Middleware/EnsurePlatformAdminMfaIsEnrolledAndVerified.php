<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsurePlatformAdminMfaIsEnrolledAndVerified — FirmsVault Admin Control
 * Center MFA design proposal §5. Registered in
 * AdminPanelProvider::authMiddleware() immediately after
 * Filament\Http\Middleware\Authenticate::class, so it runs on EVERY
 * authenticated request against the platform-admin panel (resources,
 * pages, the profile page, and the MFA set-up-required page itself —
 * all share the same authMiddleware() route group per Filament's own
 * routes/web.php).
 *
 * Two real gaps this closes (see mfa-design-proposal.md's "Two real
 * bypass gaps" section):
 *  1. Filament's own EnsureMultiFactorAuthenticationIsEnabled is never
 *     auto-applied by ->multiFactorAuthentication() — a naive
 *     integration would let a never-enrolled admin reach any resource
 *     directly by URL after a password-only login (no MFA provider is
 *     "enabled" for them yet, so nothing challenges them). Step 3
 *     below closes this.
 *  2. A stray/forged "remember me" recaller cookie bypasses the TOTP
 *     challenge entirely (SessionGuard::userFromRecaller() calls
 *     fireLoginEvent() directly, never touching Login::authenticate()
 *     where the challenge logic lives) — closed structurally by
 *     PlatformAdminLogin removing the checkbox so no recaller cookie is
 *     ever ISSUED for this panel, and defended in depth here (step 4)
 *     against a cookie that predates this policy or was forged/replayed.
 *
 * Five steps, always evaluated in this order, each one fail-closed:
 *
 *  1. Fresh re-read: re-fetches the PlatformAdmin row directly from the
 *     database (never trusts whatever instance the guard/session
 *     resolved earlier in the request lifecycle), so a same-request
 *     change to is_active/two_factor_* made by a concurrent
 *     request/action is never missed. A row that can no longer be
 *     found (e.g. hard-deleted mid-session — not a real code path
 *     today, since nothing deletes platform_admins, but defended
 *     anyway) is treated identically to step 2's failure.
 *  2. is_active check, FIRST and unconditional — deliberately takes
 *     precedence over every MFA-related check below, so a deactivated
 *     admin is force-logged-out with the exact same generic outcome
 *     (redirect to login) regardless of their MFA enrollment/
 *     verification state. This is what makes an authorized
 *     deactivation take effect immediately mid-session, not merely on
 *     next natural logout, and it never leaks "were they even
 *     enrolled?" to a deactivated account.
 *  3. Enrollment check — an admin with no TOTP secret saved is
 *     redirected to Filament's own set-up-required page. That page
 *     (and the logout route) are explicitly excluded from this
 *     redirect below — omitting the exclusion would infinite-loop,
 *     since both routes sit inside the very same authMiddleware()
 *     group this middleware itself runs in.
 *  4. Session-verification check — defense-in-depth against a
 *     remember-me-style bypass (see gap #2 above): if the current
 *     session was authenticated via a "remember me" recaller cookie at
 *     all (Illuminate\Auth\SessionGuard::viaRemember()), it is
 *     force-logged-out. PlatformAdminLogin never issues such a cookie
 *     for this panel, so any session reaching this branch is
 *     necessarily anomalous — a cookie left over from before this
 *     policy existed, or a forged/replayed one.
 *  5. Reset-stamp check — makes an authorized MFA reset
 *     (PlatformAdminMfaResetService::reset(), and the emergency
 *     Artisan command that goes through it) take effect immediately.
 *     AppServiceProvider's platform_admin Login-event listener stamps
 *     session('platform_admin_mfa_session_authenticated_at') with the
 *     login time; if two_factor_reset_at is non-null and is either
 *     newer than that stamp or the stamp is entirely missing (fail
 *     closed for a session this middleware cannot prove predates the
 *     reset), the session is force-logged-out — forcing the admin back
 *     through step 3's re-enrollment path on their very next request,
 *     not merely on their next natural logout/re-login.
 */
class EnsurePlatformAdminMfaIsEnrolledAndVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $panel = Filament::getCurrentPanel();
        $guard = Filament::auth();

        $authenticatedAdmin = $guard->user();

        if (! $authenticatedAdmin instanceof PlatformAdmin) {
            // Not this guard's concern (e.g. no user at all) — Authenticate::class,
            // which always runs immediately before this middleware, already
            // handles that case.
            return $next($request);
        }

        // Step 1: fresh re-read, never trust the already-resolved instance.
        $admin = PlatformAdmin::query()->find($authenticatedAdmin->getKey());

        // Step 2: is_active, fail-closed, takes precedence over everything else.
        if ($admin === null || (! $admin->is_active)) {
            return $this->forceLogout($request);
        }

        // Step 3: enrollment check.
        if (blank($admin->two_factor_secret)) {
            if ($this->isExemptRoute($request, $panel)) {
                return $next($request);
            }

            return redirect()->guest((string) $panel->getSetUpRequiredMultiFactorAuthenticationUrl());
        }

        // Step 4: session-verification (remember-me defense-in-depth).
        if (method_exists($guard, 'viaRemember') && $guard->viaRemember()) {
            return $this->forceLogout($request);
        }

        // Step 5: reset-stamp check.
        if ($admin->two_factor_reset_at !== null) {
            $sessionAuthenticatedAt = $request->session()->get('platform_admin_mfa_session_authenticated_at');

            if ($sessionAuthenticatedAt === null || $admin->two_factor_reset_at->gt($sessionAuthenticatedAt)) {
                return $this->forceLogout($request);
            }
        }

        return $next($request);
    }

    private function isExemptRoute(Request $request, $panel): bool
    {
        return $request->routeIs($panel->getSetUpRequiredMultiFactorAuthenticationRouteName())
            || $request->routeIs($panel->generateRouteName('auth.logout'));
    }

    private function forceLogout(Request $request): Response
    {
        Filament::auth()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->guest((string) Filament::getLoginUrl());
    }
}
