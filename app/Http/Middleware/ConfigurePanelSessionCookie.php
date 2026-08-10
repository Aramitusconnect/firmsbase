<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * ConfigurePanelSessionCookie — Mission 1 (Domain & Security Boundary
 * Architecture), sections 6/7. Each Filament panel now lives on its
 * own canonical hostname (app./client./admin.firmsvault.com); this
 * middleware gives each panel its own, distinctly-named, host-only
 * session cookie instead of the one shared `{app-name}-session`
 * cookie config/session.php defines by default — section 6 is
 * explicit that a broad `Domain=.firmsvault.com` cookie must never be
 * used "merely for convenience," so a session cookie set on one panel
 * must be structurally incapable of being read by another.
 *
 * Runs FIRST in every panel's ->middleware() array, before
 * EncryptCookies/StartSession — Illuminate\Session\SessionManager
 * reads session.cookie/session.domain/session.path fresh from the
 * config repository the first time its session driver is built for
 * this request (Manager::driver(), invoked from
 * StartSession::getSession()), so mutating config() here, before
 * StartSession ever runs, is picked up correctly; it is not a
 * boot-time-only value the manager already cached before this
 * middleware had a chance to run.
 *
 * session.domain is force-set to null unconditionally (host-only —
 * see class docblock above) regardless of what SESSION_DOMAIN might be
 * set to in the environment, as a defense-in-depth guarantee: a
 * misconfigured broad SESSION_DOMAIN env value can never widen any
 * panel's cookie scope.
 *
 * __Host- cookie-name prefix (section 7) is applied only when
 * session.secure is true — browsers reject a __Host- cookie set over
 * plain HTTP, so local development and the test suite (which run over
 * http://*.firmsvault.test) correctly fall back to a plain, still
 * host-only-by-omitted-domain, cookie name instead. Staging/production
 * MUST set SESSION_SECURE_COOKIE=true (see docs/ecs/env.ecs.example)
 * for every canonical hostname to be HTTPS-only per section 39, which
 * is exactly the condition that also earns them the stronger __Host-
 * cookie semantics.
 */
class ConfigurePanelSessionCookie
{
    public function handle(Request $request, Closure $next, string $surface): mixed
    {
        $secure = (bool) config('session.secure');

        config([
            'session.cookie' => ($secure ? '__Host-' : '').'firmsvault-'.$surface.'-session',
            'session.domain' => null,
            'session.path' => '/',
        ]);

        return $next($request);
    }
}
