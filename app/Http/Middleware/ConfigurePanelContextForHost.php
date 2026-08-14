<?php

namespace App\Http\Middleware;

use App\Services\CanonicalUrlService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Host-aware counterpart to ConfigurePanelSessionCookie for routes that
 * are shared across every canonical host rather than scoped to one via
 * Route::domain() — concretely POST /livewire/update, which Livewire
 * self-registers (vendor/livewire/livewire/src/Mechanisms/HandleRequests/
 * HandleRequests.php) with only the generic `web` middleware group and no
 * domain constraint, so it is reachable identically from every trusted
 * host. Every Filament panel's Login page (and every other panel/
 * myattorney Livewire component's form submission) round-trips through
 * this single shared endpoint, so — without this middleware — none of
 * those routes' own ConfigurePanelSessionCookie ever runs for the actual
 * authentication request: it succeeds under the app's plain default
 * session cookie, and the very next page load (which DOES carry the
 * panel-specific cookie, applied on that route directly) resumes a
 * different, still-unauthenticated session. Confirmed as the exact root
 * cause of the 2026-08-14 Admin-login redirect loop via real CloudWatch
 * access logs and Router::gatherRouteMiddleware() reflection proof — see
 * docs/security/ecs-image-vulnerability-exceptions.md and this commit's
 * message for the full diagnostic trail.
 *
 * Resolves the surface from the Host header via CanonicalUrlService
 * (never a hardcoded hostname) and delegates the actual cookie-naming
 * rule to ConfigurePanelSessionCookie::handle() directly (a plain method
 * call, not an extra pipeline stage) so there is exactly one definition
 * of that rule. Deliberately does NOT call Filament::setCurrentPanel()/
 * bootCurrentPanel() — proven unnecessary: Filament's own SetUpPanel
 * middleware is already automatically replayed, with the correct panel
 * ID resolved from the component's own route snapshot, via Livewire's
 * persistent-middleware mechanism (FilamentServiceProvider::
 * packageBooted()'s Livewire::addPersistentMiddleware() call) for every
 * genuine Filament-panel Livewire component. Adding it here a second
 * time would be redundant state, not a fix for anything.
 *
 * Marketing and MyAttorney are real canonical hosts but not Filament
 * panels — marketing has no session-bearing Livewire usage today, so it
 * passes through untouched; MyAttorney already has real, session-scoped
 * routes on this exact branch (routes/web.php's report-correction/
 * start-intake/accept-signed-invitation groups, all wrapped in
 * ConfigurePanelSessionCookie::class.':myattorney') including a real
 * Livewire component (App\Livewire\Marketplace\PublicIntakePage mounted
 * at /intake/{uuid}), so it is treated as its own surface here for
 * exactly the same reason admin/firm/client are — its Livewire follow-up
 * requests need the same isolated firmsvault-myattorney-session cookie
 * its initial page load already gets. The API host preserves its
 * existing (untouched) behavior — nothing about it changes here.
 *
 * Any Host outside the six canonical hosts throws a 400 rather than
 * proceeding with no surface assigned. In practice this is unreachable —
 * bootstrap/app.php's TrustHosts configuration already rejects any Host
 * header outside CanonicalUrlService::trustedHosts() before any route,
 * including this one, is ever reached — but failing closed here too is
 * cheap, explicit defense-in-depth rather than an implicit assumption
 * that TrustHosts always runs first.
 */
class ConfigurePanelContextForHost
{
    public function __construct(private readonly CanonicalUrlService $hosts) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $host = $request->getHost();

        return match (true) {
            $host === $this->hosts->adminHost() => $this->withSurface($request, $next, 'admin'),
            $host === $this->hosts->firmAppHost() => $this->withSurface($request, $next, 'firm'),
            $host === $this->hosts->clientPortalHost() => $this->withSurface($request, $next, 'client'),
            $host === $this->hosts->myAttorneyHost() => $this->withSurface($request, $next, 'myattorney'),
            $host === $this->hosts->marketingHost() => $next($request),
            $host === $this->hosts->apiHost() => $next($request),
            default => throw new BadRequestHttpException('Unrecognized host.'),
        };
    }

    private function withSurface(Request $request, Closure $next, string $surface): mixed
    {
        return app(ConfigurePanelSessionCookie::class)->handle($request, $next, $surface);
    }
}
