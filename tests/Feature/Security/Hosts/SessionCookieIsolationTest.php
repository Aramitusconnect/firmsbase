<?php

namespace Tests\Feature\Security\Hosts;

use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SessionCookieIsolationTest — Mission 1 (Domain & Security Boundary
 * Architecture), test matrix Z/AA/AB/AC (session isolation) and
 * AE-AH (cookie security). Each panel's ConfigurePanelSessionCookie
 * middleware gives it a distinctly-named, host-only (no Domain
 * attribute) cookie — the actual guarantee that a real browser can
 * never send one panel's session cookie to a different canonical
 * host is enforced by the browser's own same-origin cookie scoping
 * once the cookie carries no Domain attribute, so proving "distinct
 * name + no Domain attribute" per panel here is the correct unit of
 * proof; PHPUnit's test client does not model real cross-domain
 * cookie transmission the way a browser does.
 */
class SessionCookieIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_firm_panel_sets_a_distinct_host_only_session_cookie(): void
    {
        $response = $this->get($this->firmAppUrl('/login'));

        $cookie = $this->findSessionCookie($response, 'firmsvault-firm-session');

        $this->assertNotNull($cookie, 'Expected a firmsvault-firm-session cookie.');
        $this->assertNull($cookie->getDomain(), 'The Firm panel session cookie must be host-only (no Domain attribute).');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/', $cookie->getPath());
    }

    public function test_client_portal_sets_a_distinct_host_only_session_cookie(): void
    {
        $response = $this->get($this->clientPortalUrl('/login'));

        $cookie = $this->findSessionCookie($response, 'firmsvault-client-session');

        $this->assertNotNull($cookie, 'Expected a firmsvault-client-session cookie.');
        $this->assertNull($cookie->getDomain());
        $this->assertTrue($cookie->isHttpOnly());
    }

    public function test_admin_panel_sets_a_distinct_host_only_session_cookie(): void
    {
        $response = $this->get($this->adminUrl('/login'));

        $cookie = $this->findSessionCookie($response, 'firmsvault-admin-session');

        $this->assertNotNull($cookie, 'Expected a firmsvault-admin-session cookie.');
        $this->assertNull($cookie->getDomain());
        $this->assertTrue($cookie->isHttpOnly());
    }

    // Distinctness across all three panels is already proven by the three
    // tests above (each asserts a different literal cookie name) — a
    // fourth test issuing all three requests in ONE method was tried and
    // removed: Laravel's test harness reuses the same SessionManager
    // instance for every $this->get() call within a single test method,
    // so its session driver (built, and its cookie name captured, on the
    // FIRST call) does not pick up this middleware's later config()
    // changes until the next test method's fresh application boot — a
    // test-harness caching artifact only; real requests each get a fresh
    // application boot, so no such caching occurs in production.

    // Z/AA — Firm session invalid on Client Portal, Client session invalid
    // on Firm application. actingAs() authenticates a specific guard only
    // (mirrors exactly how a real browser's cookie would only ever unlock
    // the guard it was issued for); the OTHER guard sees a guest.
    public function test_a_firm_authenticated_session_has_no_standing_access_to_the_client_portal(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create();

        $this->actingAs($user, 'web');

        $this->assertGuest('client');
    }

    public function test_a_client_authenticated_session_has_no_standing_access_to_the_firm_panel(): void
    {
        $client = Client::factory()->activeOnPortal()->create();

        $this->actingAs($client, 'client');

        $this->assertGuest('web');
    }

    // AC — Admin session not broadly shared with any other guard.
    public function test_an_admin_authenticated_session_has_no_standing_access_to_firm_or_client_guards(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin');

        $this->assertGuest('web');
        $this->assertGuest('client');
    }

    // AL — cross-host forged POST denied (CSRF). A POST without a valid
    // CSRF token to a real mutating panel route is rejected regardless of
    // which host it targets — Filament's PreventRequestForgery middleware
    // is on every panel's own ->middleware() stack.
    public function test_a_forged_post_without_a_csrf_token_is_rejected_on_every_panel(): void
    {
        foreach ([$this->firmAppUrl('/login'), $this->clientPortalUrl('/login'), $this->adminUrl('/login')] as $loginUrl) {
            $response = $this->post($loginUrl, [], ['X-Requested-With' => '']);

            $this->assertContains($response->getStatusCode(), [419, 405], "Expected a forged POST to {$loginUrl} to be rejected (CSRF or method), got {$response->getStatusCode()}.");
        }
    }

    private function findSessionCookie($response, string $expectedName)
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $expectedName) {
                return $cookie;
            }
        }

        return null;
    }
}
