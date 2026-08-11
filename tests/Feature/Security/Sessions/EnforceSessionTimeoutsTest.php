<?php

namespace Tests\Feature\Security\Sessions;

use App\Http\Middleware\EnforceSessionTimeouts;
use App\Http\Middleware\EstablishPanelAuthGuardDefault;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * EnforceSessionTimeoutsTest — Mission 1B (Extreme Security Hardening).
 * Session-management audit finding: the only idle/absolute session-
 * timeout policy anywhere in this codebase
 * (LoginPolicyService::shouldExpireSession(), 30-minute idle) was pure
 * dead code — no caller anywhere invoked it. The effective behavior on
 * every panel, INCLUDING Admin, was the raw session.lifetime config
 * (120 minutes rolling, no absolute ceiling at all).
 *
 * Proves both limits actually fire, using stricter numbers on the
 * Admin panel (15min idle / 8h absolute) than Firm/Client (30min idle
 * / 24h absolute) per this mission's own explicit instruction not to
 * use one blanket lifetime for every role.
 */
class EnforceSessionTimeoutsTest extends TestCase
{
    use RefreshDatabase;

    public function test_idle_timeout_forces_logout_on_the_admin_panel(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $request = $this->authenticatedRequest('platform_admin', $admin);

        // 16 minutes of inactivity — 1 minute past the Admin panel's
        // 15-minute idle limit.
        $request->session()->put('security_session_login_at', time() - 60);
        $request->session()->put('security_session_last_activity_at', time() - (16 * 60));

        $response = $this->runThrough($request, 'platform_admin', 15, 480);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNull(Auth::guard('platform_admin')->user(), 'The guard must be logged out on idle timeout.');
    }

    public function test_absolute_lifetime_forces_logout_on_the_admin_panel_even_with_recent_activity(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $request = $this->authenticatedRequest('platform_admin', $admin);

        // Active 1 minute ago (well within the 15-minute idle window),
        // but the session itself started 481 minutes ago — 1 minute
        // past the Admin panel's 8-hour (480-minute) absolute ceiling.
        $request->session()->put('security_session_login_at', time() - (481 * 60));
        $request->session()->put('security_session_last_activity_at', time() - 60);

        $response = $this->runThrough($request, 'platform_admin', 15, 480);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNull(Auth::guard('platform_admin')->user(), 'The guard must be logged out on absolute-lifetime expiry, regardless of recent activity.');
    }

    public function test_a_fresh_session_within_both_limits_passes_through_and_stamps_timestamps(): void
    {
        $user = User::factory()->create();
        $request = $this->authenticatedRequest('web', $user);

        $this->assertFalse($request->session()->has('security_session_login_at'));

        $response = $this->runThrough($request, 'web', 30, 1440);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertNotNull(Auth::guard('web')->user(), 'A fresh, within-limits session must not be logged out.');
        $this->assertTrue($request->session()->has('security_session_login_at'));
        $this->assertTrue($request->session()->has('security_session_last_activity_at'));
    }

    public function test_the_client_and_firm_panels_use_the_looser_thirty_minute_thirty_hour_limits_not_admins(): void
    {
        $user = User::factory()->create();
        $request = $this->authenticatedRequest('web', $user);

        // 20 minutes idle — would trip the Admin panel's 15-minute
        // limit, but must NOT trip Firm/Client's 30-minute limit.
        $request->session()->put('security_session_login_at', time() - 60);
        $request->session()->put('security_session_last_activity_at', time() - (20 * 60));

        $response = $this->runThrough($request, 'web', 30, 1440);

        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertNotNull(Auth::guard('web')->user());
    }

    private function authenticatedRequest(string $guard, $user): Request
    {
        $request = Request::create('/'.$guard, 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        $request->setUserResolver(fn () => Auth::guard($guard)->user());
        Auth::guard($guard)->setUser($user);

        return $request;
    }

    private function runThrough(Request $request, string $guard, int $idleMinutes, int $absoluteMinutes): mixed
    {
        return (new Pipeline($this->app))
            ->send($request)
            ->through([
                EstablishPanelAuthGuardDefault::class.':'.$guard,
                EnforceSessionTimeouts::class.':'.$idleMinutes.','.$absoluteMinutes,
            ])
            ->then(fn () => new Response('ok'));
    }
}
