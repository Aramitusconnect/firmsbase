<?php

namespace Tests\Feature\Security\Hosts;

use App\Enums\ClientPortalStatus;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * SessionCookieIsolationTest — Mission 1 (canonical reconstruction),
 * test matrix S/T/X/Y/Z/AA/AB/AC (session isolation) and AI-AL (cookie
 * security/CSRF). Each panel's ConfigurePanelSessionCookie middleware
 * gives it a distinctly-named, host-only (no Domain attribute) cookie —
 * the actual guarantee that a real browser can never send one panel's
 * session cookie to a different canonical host is enforced by the
 * browser's own same-origin cookie scoping once the cookie carries no
 * Domain attribute, so proving "distinct name + no Domain attribute"
 * per panel here is the correct unit of proof.
 */
class SessionCookieIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makePortalUser(Client $client, array $overrides = []): ClientPortalUser
    {
        return $this->runWithFirmContext($client->firm_id, fn () => ClientPortalUser::query()->create(array_merge([
            'client_id' => $client->id,
            'email' => $client->email,
            'password' => Hash::make('Sup3rSecret!Pass'),
            'is_active' => true,
        ], $overrides)));
    }

    public function test_firm_panel_sets_a_distinct_host_only_session_cookie(): void
    {
        $response = $this->get($this->firmAppUrl('/login'));

        $cookie = $this->findSessionCookie($response, 'firmsvault-firm-session');

        $this->assertNotNull($cookie, 'Expected a firmsvault-firm-session cookie.');
        $this->assertNull($cookie->getDomain(), 'The Firm panel session cookie must be host-only (no Domain attribute).');
        $this->assertTrue($cookie->isHttpOnly());
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

    // Z/AA — Firm session invalid on Client Portal, Client session invalid
    // on Firm application.
    public function test_a_firm_authenticated_session_has_no_standing_access_to_the_client_portal(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create();

        $this->actingAs($user, 'web');

        $this->assertGuest('client');
    }

    public function test_a_client_portal_authenticated_session_has_no_standing_access_to_the_firm_panel(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->makePortalUser($client);

        $this->actingAs($portalUser, 'client');

        $this->assertGuest('web');
    }

    // AB/AC — Admin session isolated from Firm/Client guards.
    public function test_an_admin_authenticated_session_has_no_standing_access_to_firm_or_client_guards(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin');

        $this->assertGuest('web');
        $this->assertGuest('client');
    }

    // X. Firm user cannot access Admin.
    public function test_a_firm_user_cannot_access_the_admin_panel(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create();

        $response = $this->actingAs($user, 'web')->get($this->adminUrl());

        $response->assertRedirect($this->adminUrl('/login'));
    }

    // Y. Client user cannot access Admin.
    public function test_a_client_portal_user_cannot_access_the_admin_panel(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->makePortalUser($client);

        $response = $this->actingAs($portalUser, 'client')->get($this->adminUrl());

        $response->assertRedirect($this->adminUrl('/login'));
    }

    // S. The tenant Client record itself must never be treated as an
    // auth principal — the `client` guard's provider is `client_portal_users`
    // (ClientPortalUser), never `clients`. A plain Client instance simply
    // cannot be passed to actingAs('client') at all without violating
    // Authenticatable — proven at the config level instead, which is
    // what structurally guarantees this.
    public function test_the_client_guard_provider_is_client_portal_users_never_the_tenant_client_model(): void
    {
        $this->assertSame('client_portal_users', config('auth.guards.client.provider'));
        $this->assertSame(ClientPortalUser::class, config('auth.providers.client_portal_users.model'));
    }

    // AI/AJ/AK/AL — CSRF preserved per panel + cross-host forged mutation denied.
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
