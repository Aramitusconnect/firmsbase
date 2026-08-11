<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\FirmUser2faPolicyService;
use App\Services\TenantContextService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureFirmUserMfaComplianceOrRedirectToEnrollment — Mission 1C
 * (Security Validation, Activation & Staging Proof), section 5. Closes
 * the exact lockout risk Mission 1B found and deliberately left
 * alone: `User::canAccessPanel()` used to hard-deny the ENTIRE Firm
 * panel (a 403, on every request — see below) for a non-compliant
 * user whose firm requires 2FA, with no path to reach any page,
 * including one that could fix it. That check has been moved out of
 * `canAccessPanel()` into this middleware, which redirects instead of
 * denying.
 *
 * Registered in FirmPanelProvider::authMiddleware() immediately after
 * Filament\Http\Middleware\Authenticate::class, so — exactly like
 * EnsurePlatformAdminMfaIsEnrolledAndVerified on the Admin panel — it
 * runs on every authenticated request against every Firm panel route
 * (resources, pages, the profile page itself; confirmed by direct
 * reading of vendor/filament/filament/routes/web.php: every route
 * inside a panel's `Route::middleware($panel->getAuthMiddleware())`
 * group, no exceptions).
 *
 * Why this can't just be Filament's own `isRequired` + native
 * "set-up-required" redirect (the mechanism the Admin panel uses):
 * confirmed by direct reading of Filament's own source that
 * `Panel::isMultiFactorAuthenticationRequired()` is evaluated ONCE, at
 * route-registration time (inside `HasRoutes::getRouteMiddleware()`,
 * itself called from route-closures that execute before Laravel's
 * middleware pipeline/auth/session/tenant-context ever runs) — there
 * is no user, no session, no tenant context available at that point,
 * so it can only ever be a single static panel-wide boolean. It
 * structurally cannot express "required for FIRM A's users, optional
 * for FIRM B's" the way `firm_user_2fa_mode` genuinely is per firm.
 * `FirmPanelProvider` therefore correctly keeps `isRequired: false`
 * unchanged — Filament's own "set-up-required" route isn't even
 * registered when that's false (see
 * vendor/filament/filament/routes/web.php:105) — and this middleware
 * does the real, per-firm-aware enforcement entirely independently,
 * using the exact same `FirmUser2faPolicyService` +
 * `TenantContextService::runWithFirmContext()` combination
 * `canAccessPanel()` used to run.
 *
 * The redirect target is the Firm panel's own profile page
 * (`$panel->getProfileUrl()`), NOT Filament's "set-up-required" page —
 * that route doesn't exist for this panel (see above). Filament's
 * `EditProfile` page already renders MFA-management UI for every
 * registered provider regardless of `isRequired`, via
 * `getMultiFactorAuthenticationContentComponent()` — no separate page
 * needs to be built for this to work.
 *
 * The profile route (and logout) are exempt from the redirect —
 * omitting that exemption would infinite-loop, since both sit inside
 * the very same `authMiddleware()` group this middleware runs in.
 */
class EnsureFirmUserMfaComplianceOrRedirectToEnrollment
{
    public function handle(Request $request, Closure $next): Response
    {
        $panel = Filament::getCurrentPanel();
        $guard = Filament::auth();
        $user = $guard->user();

        if (! $user instanceof User) {
            // Not this guard's concern — Authenticate::class, which
            // always runs immediately before this middleware, already
            // handles the no-user case.
            return $next($request);
        }

        $firmUser = $user->activeFirmUser();

        if ($firmUser === null) {
            // No active membership at all — canAccessPanel() already
            // denies this case entirely; nothing for this middleware
            // to add.
            return $next($request);
        }

        $policy = new FirmUser2faPolicyService;

        // Mirrors canAccessPanel()'s own prior wrapping exactly:
        // firm_settings is FORCE-RLS protected and both
        // isRequiredForFirmUser() and isCompliant() read it, so the
        // whole decision is wrapped in one context.
        $blocksAccess = (new TenantContextService)->runWithFirmContext(
            $firmUser->firm_id,
            fn () => $policy->isRequiredForFirmUser($firmUser) && ! $policy->isCompliant($firmUser)
        );

        if (! $blocksAccess) {
            return $next($request);
        }

        if ($this->isExemptRoute($request, $panel)) {
            return $next($request);
        }

        return redirect()->guest((string) $panel->getProfileUrl());
    }

    private function isExemptRoute(Request $request, $panel): bool
    {
        return $request->routeIs($panel->generateRouteName('auth.profile'))
            || $request->routeIs($panel->generateRouteName('auth.logout'));
    }
}
