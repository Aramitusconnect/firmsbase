<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Hosts;

use App\Enums\ClientPortalStatus;
use App\Http\Middleware\ConfigurePanelContextForHost;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\CanonicalUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tests\TestCase;

/**
 * Regression coverage for the 2026-08-14 Admin/Firm/Client login redirect
 * loop. Root cause: POST /livewire/update is one shared route across every
 * canonical host (AppServiceProvider::registerLivewireUpdateRoute()'s
 * override of Livewire's own self-registration), so the real login POST
 * never ran ConfigurePanelSessionCookie — it succeeded under the app's
 * plain default session cookie, and the very next real page load (which
 * DOES run that middleware, on its own domain-bound panel route) resumed a
 * different, still-unauthenticated session under the correct panel cookie
 * name. Confirmed via real CloudWatch access logs
 * (GET /login -> POST /livewire/update x2 -> GET / -> 302 -> /login,
 * repeating) before this fix, and via reflection-based
 * Router::gatherRouteMiddleware() proof of the actual resolved middleware
 * order both before and after.
 *
 * ConfigurePanelContextForHost (see its own docblock) closes this by
 * resolving the surface from the Host header — via CanonicalUrlService,
 * never a hardcoded hostname — and delegating to
 * ConfigurePanelSessionCookie::handle() directly, guaranteed to run before
 * StartSession via the SAME bootstrap/app.php prependToPriorityList()
 * mechanism this codebase already uses for ConfigurePanelSessionCookie
 * itself on ordinary routes.
 */
class LivewirePanelSessionContextTest extends TestCase
{
    use RefreshDatabase;

    private function middleware(): ConfigurePanelContextForHost
    {
        return app(ConfigurePanelContextForHost::class);
    }

    private function requestFor(string $host): Request
    {
        $request = Request::create('http://'.$host.'/livewire/update', 'POST');
        $request->headers->set('Host', $host);

        return $request;
    }

    // ============================================================
    // Host resolution — every canonical host must resolve correctly
    // ============================================================

    public function test_admin_host_gets_the_admin_session_cookie_surface(): void
    {
        $host = app(CanonicalUrlService::class)->adminHost();

        $this->middleware()->handle($this->requestFor($host), fn ($r) => new Response('ok'));

        $this->assertStringContainsString('firmsvault-admin-session', config('session.cookie'));
    }

    public function test_firm_app_host_gets_the_firm_session_cookie_surface(): void
    {
        $host = app(CanonicalUrlService::class)->firmAppHost();

        $this->middleware()->handle($this->requestFor($host), fn ($r) => new Response('ok'));

        $this->assertStringContainsString('firmsvault-firm-session', config('session.cookie'));
    }

    public function test_client_portal_host_gets_the_client_session_cookie_surface(): void
    {
        $host = app(CanonicalUrlService::class)->clientPortalHost();

        $this->middleware()->handle($this->requestFor($host), fn ($r) => new Response('ok'));

        $this->assertStringContainsString('firmsvault-client-session', config('session.cookie'));
    }

    /**
     * MyAttorney already has real, session-scoped routes on this branch
     * (routes/web.php's report-correction/start-intake/accept-signed-
     * invitation groups, all wrapped in
     * ConfigurePanelSessionCookie::class.':myattorney') including a real
     * Livewire component (App\Livewire\Marketplace\PublicIntakePage,
     * mounted at /intake/{uuid}) — its Livewire follow-up requests need
     * the SAME isolated cookie its initial page load already gets, or the
     * intake wizard would suffer the identical redirect-loop-shaped defect
     * this whole fix exists to close.
     */
    public function test_myattorney_host_gets_the_myattorney_session_cookie_surface(): void
    {
        $host = app(CanonicalUrlService::class)->myAttorneyHost();

        $this->middleware()->handle($this->requestFor($host), fn ($r) => new Response('ok'));

        $this->assertStringContainsString('firmsvault-myattorney-session', config('session.cookie'));
    }

    /**
     * Marketing has no session-bearing Livewire usage today and is not a
     * Filament panel — it must pass through with NO panel authentication
     * context assigned, exactly as the owner required ("must NOT
     * automatically return 400... must continue through the normal
     * Livewire/web request path without being assigned Admin/Firm/Client
     * panel authentication context").
     */
    public function test_marketing_host_passes_through_without_any_panel_cookie_surface(): void
    {
        $host = app(CanonicalUrlService::class)->marketingHost();
        $defaultCookie = config('session.cookie');

        $this->middleware()->handle($this->requestFor($host), fn ($r) => new Response('ok'));

        $this->assertSame($defaultCookie, config('session.cookie'), 'Marketing must not receive any panel-specific session cookie.');
    }

    /**
     * The API host's existing behavior must be preserved untouched — no
     * panel context invented for it.
     */
    public function test_api_host_passes_through_without_any_panel_cookie_surface(): void
    {
        $host = app(CanonicalUrlService::class)->apiHost();
        $defaultCookie = config('session.cookie');

        $this->middleware()->handle($this->requestFor($host), fn ($r) => new Response('ok'));

        $this->assertSame($defaultCookie, config('session.cookie'), 'The API host must not receive any panel-specific session cookie.');
    }

    /**
     * Unreachable in production (TrustHosts already rejects anything
     * outside the six canonical hosts before any route, including this
     * one, is ever reached) but must fail closed here too as independent
     * defense-in-depth — never fall back to a default panel/guard.
     */
    public function test_an_unrecognized_host_is_rejected_rather_than_defaulted(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->middleware()->handle(
            $this->requestFor('evil-attacker.example.com'),
            fn ($r) => new Response('ok'),
        );
    }

    // ============================================================
    // Middleware order — proven against the REAL resolved route, not
    // source-code reading. Router::gatherRouteMiddleware() is the same
    // internal method Laravel itself uses to build the actual pipeline
    // (it calls sortMiddleware() -> SortedMiddleware using the real
    // priority list), so this is the genuine, final execution order.
    // ============================================================

    public function test_configure_panel_context_for_host_resolves_before_start_session_on_the_real_route(): void
    {
        foreach ([
            app(CanonicalUrlService::class)->adminHost(),
            app(CanonicalUrlService::class)->marketingHost(),
        ] as $host) {
            $request = $this->requestFor($host);

            $route = app('router')->getRoutes()->match($request);
            $this->assertSame('livewire.update', $route->getName());

            $ref = new ReflectionMethod(app('router'), 'gatherRouteMiddleware');
            $ref->setAccessible(true);
            $resolved = $ref->invoke(app('router'), $route);

            $panelIndex = array_search(ConfigurePanelContextForHost::class, $resolved, true);
            $sessionIndex = array_search(StartSession::class, $resolved, true);

            $this->assertNotFalse($panelIndex, "ConfigurePanelContextForHost must be present in the resolved middleware for host {$host}.");
            $this->assertNotFalse($sessionIndex, "StartSession must be present in the resolved middleware for host {$host}.");
            $this->assertLessThan(
                $sessionIndex,
                $panelIndex,
                "ConfigurePanelContextForHost must resolve BEFORE StartSession for host {$host} — otherwise config('session.cookie') mutates too late for SessionManager to pick up (see ConfigurePanelSessionCookie's own docblock)."
            );
        }
    }

    // ============================================================
    // The actual original defect — proven end-to-end: a login that runs
    // through this exact middleware chain, then a completely separate,
    // real HTTP GET request (full kernel, including StartSession) that
    // carries only the resulting session cookie forward — exactly what a
    // real browser's next page load does. Before this fix, request 2
    // would resume a DIFFERENT session under the correct panel cookie
    // name (never touched by request 1) and bounce back to /login.
    // ============================================================

    public function test_admin_login_survives_the_livewire_update_then_page_load_round_trip(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $adminHost = app(CanonicalUrlService::class)->adminHost();

        [$cookieName, $encryptedSessionId] = $this->driveLoginThroughMiddleware($adminHost, function (Request $req) use ($admin) {
            Auth::guard('platform_admin')->login($admin);
            $req->session()->regenerate();
        });

        $response = $this->withUnencryptedCookie($cookieName, $encryptedSessionId)->get('http://'.$adminHost.'/');

        $location = $response->headers->get('Location');
        $this->assertNotSame(
            app(CanonicalUrlService::class)->adminUrl().'/login',
            $location,
            'The session must not disappear — this is exactly the original bug. A redirect to an MFA challenge (this factory-created admin has no MFA enrolled yet) is an acceptable, expected outcome; a bounce back to /login is not.'
        );
    }

    public function test_firm_login_survives_the_livewire_update_then_page_load_round_trip(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create();
        $firmHost = app(CanonicalUrlService::class)->firmAppHost();

        [$cookieName, $encryptedSessionId] = $this->driveLoginThroughMiddleware($firmHost, function (Request $req) use ($user) {
            Auth::guard('web')->login($user);
            $req->session()->regenerate();
        });

        $response = $this->withUnencryptedCookie($cookieName, $encryptedSessionId)->get('http://'.$firmHost.'/');

        $this->assertNotSame(
            app(CanonicalUrlService::class)->firmAppUrl().'/login',
            $response->headers->get('Location'),
            'A firm login must not be bounced back to /login after the Livewire round trip.'
        );
    }

    public function test_client_login_survives_the_livewire_update_then_page_load_round_trip(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->runWithFirmContext($firm, fn () => ClientPortalUser::query()->create([
            'client_id' => $client->id,
            'email' => $client->email,
            'password' => Hash::make('Sup3rSecret!Pass'),
            'is_active' => true,
        ]));
        $clientHost = app(CanonicalUrlService::class)->clientPortalHost();

        [$cookieName, $encryptedSessionId] = $this->driveLoginThroughMiddleware($clientHost, function (Request $req) use ($portalUser) {
            Auth::guard('client')->login($portalUser);
            $req->session()->regenerate();
        });

        $response = $this->withUnencryptedCookie($cookieName, $encryptedSessionId)->get('http://'.$clientHost.'/');

        $this->assertNotSame(
            app(CanonicalUrlService::class)->clientPortalUrl().'/login',
            $response->headers->get('Location'),
            'A client login must not be bounced back to /login after the Livewire round trip.'
        );
    }

    /**
     * Drives a real session through the SAME middleware chain the actual
     * /livewire/update route uses (ConfigurePanelContextForHost, then a
     * closure standing in for Filament\Auth\Pages\Login::authenticate(),
     * which is exactly Auth::guard()->login() + session()->regenerate()).
     * Returns [cookieName, encryptedSessionIdForCookieHeader] so the
     * caller can carry the resulting session into a genuinely separate,
     * full-kernel HTTP request — matching Illuminate\Cookie\Middleware\
     * EncryptCookies::encrypt()'s own encryption (serialize: false, the
     * default for any cookie name not in its $serialize allowlist).
     *
     * @return array{0: string, 1: string}
     */
    private function driveLoginThroughMiddleware(string $host, \Closure $loginAction): array
    {
        $request = $this->requestFor($host);
        $request->setLaravelSession($this->app['session']->driver());

        $this->middleware()->handle($request, function (Request $req) use ($loginAction) {
            $req->session()->start();
            $loginAction($req);

            return new Response('ok');
        });

        $sessionId = $request->session()->getId();
        $this->app['session']->driver()->save();

        return [config('session.cookie'), encrypt($sessionId, false)];
    }

    // ============================================================
    // Cross-panel isolation — a session cookie for one panel/surface must
    // never authenticate a different one.
    // ============================================================

    public function test_admin_session_cookie_cannot_authenticate_the_firm_panel(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $adminHost = app(CanonicalUrlService::class)->adminHost();

        [$cookieName, $encryptedSessionId] = $this->driveLoginThroughMiddleware($adminHost, function (Request $req) use ($admin) {
            Auth::guard('platform_admin')->login($admin);
            $req->session()->regenerate();
        });

        $firmHost = app(CanonicalUrlService::class)->firmAppHost();
        $response = $this->withUnencryptedCookie($cookieName, $encryptedSessionId)->get('http://'.$firmHost.'/');

        $response->assertRedirect(app(CanonicalUrlService::class)->firmAppUrl().'/login');
    }

    public function test_firm_session_cookie_cannot_authenticate_the_admin_panel(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create();
        $firmHost = app(CanonicalUrlService::class)->firmAppHost();

        [$cookieName, $encryptedSessionId] = $this->driveLoginThroughMiddleware($firmHost, function (Request $req) use ($user) {
            Auth::guard('web')->login($user);
            $req->session()->regenerate();
        });

        $adminHost = app(CanonicalUrlService::class)->adminHost();
        $response = $this->withUnencryptedCookie($cookieName, $encryptedSessionId)->get('http://'.$adminHost.'/');

        $response->assertRedirect(app(CanonicalUrlService::class)->adminUrl().'/login');
    }

    public function test_client_session_cookie_cannot_authenticate_the_firm_panel(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->runWithFirmContext($firm, fn () => ClientPortalUser::query()->create([
            'client_id' => $client->id,
            'email' => $client->email,
            'password' => Hash::make('Sup3rSecret!Pass'),
            'is_active' => true,
        ]));
        $clientHost = app(CanonicalUrlService::class)->clientPortalHost();

        [$cookieName, $encryptedSessionId] = $this->driveLoginThroughMiddleware($clientHost, function (Request $req) use ($portalUser) {
            Auth::guard('client')->login($portalUser);
            $req->session()->regenerate();
        });

        $firmHost = app(CanonicalUrlService::class)->firmAppHost();
        $response = $this->withUnencryptedCookie($cookieName, $encryptedSessionId)->get('http://'.$firmHost.'/');

        $response->assertRedirect(app(CanonicalUrlService::class)->firmAppUrl().'/login');
    }

    // ============================================================
    // Non-panel Livewire regression — the new middleware must not make
    // marketing/myattorney/api resolve to any panel guard.
    // ============================================================

    public function test_myattorney_host_never_resolves_a_panel_guard(): void
    {
        $host = app(CanonicalUrlService::class)->myAttorneyHost();

        $this->middleware()->handle($this->requestFor($host), function () {
            $this->assertFalse(Auth::guard('platform_admin')->check());
            $this->assertFalse(Auth::guard('web')->check());
            $this->assertFalse(Auth::guard('client')->check());

            return new Response('ok');
        });
    }

    public function test_marketing_host_never_resolves_a_panel_guard(): void
    {
        $host = app(CanonicalUrlService::class)->marketingHost();

        $this->middleware()->handle($this->requestFor($host), function () {
            $this->assertFalse(Auth::guard('platform_admin')->check());
            $this->assertFalse(Auth::guard('web')->check());
            $this->assertFalse(Auth::guard('client')->check());

            return new Response('ok');
        });
    }

    // ============================================================
    // Sequential-request isolation. This app's actual runtime (see
    // docker/web/Caddyfile: "FrankenPHP classic (non-worker) mode... zero
    // application code changes required") boots a fresh Application per
    // real HTTP request, so config() state can never carry between real
    // requests in production — confirmed during this fix's own
    // investigation (no laravel/octane dependency, no worker directive
    // anywhere). Within a single PHPUnit process, though, config() IS a
    // persisted global across these manual sequential ->handle() calls
    // (there is no per-call app reboot), so the meaningful thing to prove
    // here is that the middleware itself never ACTIVELY resolves a
    // non-panel host to a stale panel surface — not that config() reverts
    // to some prior value on its own, which it never needs to in the real
    // per-request-fresh-boot runtime.
    // ============================================================

    public function test_sequential_requests_across_hosts_each_resolve_independently(): void
    {
        $hosts = app(CanonicalUrlService::class);
        $defaultCookie = config('session.cookie');
        $sequence = [
            [$hosts->adminHost(), 'firmsvault-admin-session'],
            [$hosts->myAttorneyHost(), 'firmsvault-myattorney-session'],
            [$hosts->firmAppHost(), 'firmsvault-firm-session'],
            [$hosts->marketingHost(), null],
            [$hosts->clientPortalHost(), 'firmsvault-client-session'],
        ];

        foreach ($sequence as [$host, $expectedSurfaceFragment]) {
            // Reset to a neutral starting point before each iteration —
            // matching what every REAL request gets for free from a
            // fresh Application boot (this app's actual runtime; see the
            // class docblock above this test). Manually invoking the
            // middleware repeatedly within one PHPUnit process is the
            // only place config() can persist between "requests" at
            // all — proving the middleware resolves each host correctly
            // from a clean slate is the accurate analogue of production,
            // not proving config() magically resets itself, which it
            // never needs to for real HTTP requests.
            config(['session.cookie' => $defaultCookie]);

            $this->middleware()->handle($this->requestFor($host), fn ($r) => new Response('ok'));

            if ($expectedSurfaceFragment === null) {
                $this->assertSame($defaultCookie, config('session.cookie'), "Host {$host} must not be assigned any panel-specific session cookie.");
            } else {
                $this->assertStringContainsString($expectedSurfaceFragment, config('session.cookie'), "Host {$host} resolved the wrong surface.");
            }
        }
    }
}
