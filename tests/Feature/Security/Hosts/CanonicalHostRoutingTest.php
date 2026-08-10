<?php

namespace Tests\Feature\Security\Hosts;

use App\Services\CanonicalUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CanonicalHostRoutingTest — Mission 1 (Domain & Security Boundary
 * Architecture), test matrix A-G (host routing) and AM-AP (legacy
 * route compatibility). Proves each canonical hostname serves the
 * right surface and nothing else, an unrecognized Host is rejected
 * safely, and the pre-Mission-1 /firm and /admin paths still work via
 * a GET-only redirect that never touches a state-changing request.
 */
class CanonicalHostRoutingTest extends TestCase
{
    use RefreshDatabase;

    // A. firmsvault.com serves marketing.
    public function test_marketing_host_serves_the_marketing_welcome_page(): void
    {
        $response = $this->get($this->marketingUrl('/'));

        $response->assertOk();
        $response->assertSee('Laravel', false);
    }

    // B. app.firmsvault.com serves the Firm panel.
    public function test_firm_app_host_serves_the_firm_panel_login_page(): void
    {
        $response = $this->get($this->firmAppUrl('/login'));

        $response->assertOk();
    }

    // C. client.firmsvault.com serves the Client Portal.
    public function test_client_portal_host_serves_the_client_portal_login_page(): void
    {
        $response = $this->get($this->clientPortalUrl('/login'));

        $response->assertOk();
    }

    // D. admin.firmsvault.com serves SuperAdmin.
    public function test_admin_host_serves_the_admin_panel_login_page(): void
    {
        $response = $this->get($this->adminUrl('/login'));

        $response->assertOk();
    }

    // E. MyAttorney does not expose Firm routes.
    public function test_myattorney_host_never_exposes_the_firm_panel(): void
    {
        $response = $this->get($this->myAttorneyUrl('/login'));

        $response->assertOk();
        $response->assertDontSee('filament', false);
    }

    // F. API hostname exposes no unintended privileged route.
    public function test_api_host_is_reserved_and_exposes_nothing(): void
    {
        $response = $this->get(app(CanonicalUrlService::class)->apiUrl().'/anything');

        $response->assertNotFound();
    }

    // G. Unknown Host rejected safely (no route matches any registered domain).
    public function test_an_unrecognized_host_is_rejected_safely(): void
    {
        $response = $this->get('http://not-a-firmsvault-host.example/');

        $response->assertNotFound();
    }

    // AM. old Firm GET redirect.
    public function test_legacy_firm_path_get_redirects_to_the_canonical_firm_host(): void
    {
        $response = $this->get($this->marketingUrl('/firm/matters/123?foo=bar'));

        $response->assertRedirect($this->firmAppUrl('/matters/123?foo=bar'));
    }

    public function test_legacy_firm_root_get_redirects_to_the_canonical_firm_host_root(): void
    {
        $response = $this->get($this->marketingUrl('/firm'));

        $response->assertRedirect($this->firmAppUrl('/'));
    }

    // AN. old Admin GET redirect.
    public function test_legacy_admin_path_get_redirects_to_the_canonical_admin_host(): void
    {
        $response = $this->get($this->marketingUrl('/admin/users'));

        $response->assertRedirect($this->adminUrl('/users'));
    }

    // AO. unsafe state-changing redirect prevented — a POST to the legacy
    // path is never silently replayed cross-host; it simply finds no
    // matching route/method (safe either way — never a redirect).
    public function test_legacy_firm_path_post_is_never_redirected(): void
    {
        $response = $this->post($this->marketingUrl('/firm/matters'));

        $this->assertNotEquals(302, $response->getStatusCode());
        $response->assertStatus(405);
    }

    public function test_legacy_admin_path_post_is_never_redirected(): void
    {
        $response = $this->post($this->marketingUrl('/admin/users'));

        $this->assertNotEquals(302, $response->getStatusCode());
        $response->assertStatus(405);
    }

    // AP. return URL validation / open-redirect protection — the redirect
    // target is always built from CanonicalUrlService + the request's own
    // path, never a caller-supplied parameter, so there is no destination
    // to manipulate. Confirms the query string cannot smuggle a different
    // redirect target.
    public function test_legacy_redirect_ignores_any_attempted_redirect_override_parameter(): void
    {
        $response = $this->get($this->marketingUrl('/firm/dashboard?redirect=https://evil.example'));

        $response->assertStatus(302);
        $location = $response->headers->get('Location');

        $this->assertSame(
            app(CanonicalUrlService::class)->firmAppHost(),
            parse_url($location, PHP_URL_HOST),
            'The legacy redirect must always land on the canonical Firm app host, never a value smuggled through a query parameter.'
        );
    }
}
