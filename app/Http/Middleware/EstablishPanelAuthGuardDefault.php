<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

/**
 * EstablishPanelAuthGuardDefault — Mission 1B (Extreme Security
 * Hardening). Fixes a real bug the session-management audit found:
 * `Illuminate\Session\Middleware\AuthenticateSession` (listed in every
 * panel's own ->middleware() array, including Admin and Client Portal)
 * is hardcoded to the CONTAINER'S DEFAULT guard throughout its
 * implementation — `$request->user()` with no argument,
 * `$this->auth->getDefaultDriver()` for the session key, and every
 * `$this->guard()->...()` call (its own `guard()` method just returns
 * the AuthFactory, whose magic `__call` proxies to `guard(null)` =
 * the default) all resolve against `config('auth.defaults.guard')`
 * (`web`), never the panel's own guard. Filament's own `Authenticate`
 * middleware DOES call `Auth::shouldUse($panelGuard)` — but only
 * inside ->authMiddleware(), which runs AFTER the outer ->middleware()
 * group AuthenticateSession lives in. Net effect: on the Admin
 * (`platform_admin`) and Client Portal (`client`) panels,
 * AuthenticateSession's password-hash-staleness check silently never
 * ran — a compromised/stale session on either panel would survive a
 * password change indefinitely, unlike the Firm panel (which happens
 * to use the `web` guard, the actual default, so it worked by
 * accident).
 *
 * The fix is exactly what Filament's own Authenticate middleware does,
 * just moved earlier: switch the container's default guard for the
 * rest of this request BEFORE AuthenticateSession runs. `Auth::guard()`,
 * `$request->user()`, and `getDefaultDriver()` all read
 * `config('auth.defaults.guard')` fresh on each call (see
 * AuthManager::guard()/getDefaultDriver()/shouldUse()), so this is
 * sufficient — no need to reimplement AuthenticateSession itself.
 * Calling shouldUse() again later inside Filament's own Authenticate
 * middleware (same guard, same panel) is a harmless no-op.
 *
 * Must run before StartSession/AuthenticateSession in each panel's
 * ->middleware() array — placed alongside ConfigurePanelSessionCookie
 * at the very front.
 */
class EstablishPanelAuthGuardDefault
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(Request $request, Closure $next, string $guard): mixed
    {
        $this->auth->shouldUse($guard);

        return $next($request);
    }
}
