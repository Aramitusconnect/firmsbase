<?php

namespace Tests\Feature\Security\Sessions;

use App\Http\Middleware\EstablishPanelAuthGuardDefault;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\User;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GuardAwareAuthenticateSessionTest — Mission 1B (Extreme Security
 * Hardening). Session-management audit finding: `AuthenticateSession`
 * is hardcoded throughout its implementation to the CONTAINER'S
 * DEFAULT guard (`$request->user()`, `getDefaultDriver()`, and every
 * `$this->guard()->...()` call all resolve `config('auth.defaults.guard')`).
 * Filament's own `Authenticate` middleware calls `Auth::shouldUse($panelGuard)`
 * — but only inside ->authMiddleware(), which runs AFTER the outer
 * ->middleware() group AuthenticateSession lives in. Net effect,
 * proven here directly against the real middleware (not just checking
 * it's LISTED in the panel's middleware array, which a pre-existing
 * test already did without ever proving it actually works): before
 * this mission's fix, a stale/compromised Admin or Client Portal
 * session survived a password change indefinitely; the Firm panel
 * happened to work by accident (its guard IS the container default).
 *
 * EstablishPanelAuthGuardDefault, placed before AuthenticateSession in
 * every panel's ->middleware() array, switches the default guard early
 * enough for AuthenticateSession's staleness check to actually run
 * against the correct guard.
 */
class GuardAwareAuthenticateSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stale_password_hash_is_detected_on_the_admin_guard(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true, 'password' => Hash::make('OldPassword!123')]);

        $request = $this->requestFor('admin');
        $request->setUserResolver(fn () => Auth::guard('platform_admin')->user());
        Auth::guard('platform_admin')->setUser($admin);

        // First pass: no password_hash_platform_admin key in session yet
        // — the middleware stores it and lets the request through.
        $response = $this->runThroughMiddleware($request, 'platform_admin');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($request->session()->has('password_hash_platform_admin'));

        // Simulate the password being changed elsewhere (a real password
        // reset, or an attacker's own credential change) — the guard now
        // resolves a user whose password hash no longer matches what this
        // session stored on the prior request.
        $admin->forceFill(['password' => Hash::make('NewPassword!456')])->save();
        Auth::guard('platform_admin')->setUser($admin->fresh());

        $this->expectException(AuthenticationException::class);
        $this->runThroughMiddleware($request, 'platform_admin');
    }

    public function test_a_stale_password_hash_is_detected_on_the_client_guard(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $portalUser = $this->runWithFirmContext($firm, fn () => ClientPortalUser::query()->create([
            'client_id' => $client->id,
            'email' => $client->email,
            'password' => Hash::make('OldPassword!123'),
            'is_active' => true,
        ]));

        $request = $this->requestFor('client');
        $request->setUserResolver(fn () => Auth::guard('client')->user());
        Auth::guard('client')->setUser($portalUser);

        $response = $this->runThroughMiddleware($request, 'client');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($request->session()->has('password_hash_client'));

        $portalUser->forceFill(['password' => Hash::make('NewPassword!456')])->save();
        Auth::guard('client')->setUser($portalUser->fresh());

        $this->expectException(AuthenticationException::class);
        $this->runThroughMiddleware($request, 'client');
    }

    public function test_the_firm_guard_still_works_as_before_the_fix(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPassword!123')]);

        $request = $this->requestFor('web');
        $request->setUserResolver(fn () => Auth::guard('web')->user());
        Auth::guard('web')->setUser($user);

        $response = $this->runThroughMiddleware($request, 'web');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($request->session()->has('password_hash_web'));

        $user->forceFill(['password' => Hash::make('NewPassword!456')])->save();
        Auth::guard('web')->setUser($user->fresh());

        $this->expectException(AuthenticationException::class);
        $this->runThroughMiddleware($request, 'web');
    }

    private function requestFor(string $guard): Request
    {
        $request = Request::create('/'.$guard, 'GET');
        $request->setLaravelSession($this->app['session']->driver());

        return $request;
    }

    private function runThroughMiddleware(Request $request, string $guard): mixed
    {
        return (new Pipeline($this->app))
            ->send($request)
            ->through([
                EstablishPanelAuthGuardDefault::class.':'.$guard,
                AuthenticateSession::class,
            ])
            ->then(fn () => new Response('ok'));
    }
}
