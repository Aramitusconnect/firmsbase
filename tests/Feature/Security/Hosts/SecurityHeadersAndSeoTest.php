<?php

namespace Tests\Feature\Security\Hosts;

use App\Services\CanonicalUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SecurityHeadersAndSeoTest — Mission 1 (canonical reconstruction),
 * test matrix BL-BP (SEO boundaries) and the baseline security
 * headers. Both are applied by global middleware (AddSecurityHeaders,
 * AddSearchIndexingHeader — see bootstrap/app.php) so they cover every
 * canonical hostname uniformly.
 */
class SecurityHeadersAndSeoTest extends TestCase
{
    use RefreshDatabase;

    // BL. marketing indexable.
    public function test_marketing_host_has_no_noindex_header(): void
    {
        $response = $this->get($this->marketingUrl('/'));

        $response->assertHeaderMissing('X-Robots-Tag');
    }

    // BM. app noindex.
    public function test_firm_app_host_is_noindex(): void
    {
        $response = $this->get($this->firmAppUrl('/login'));

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    // BN. client noindex.
    public function test_client_portal_host_is_noindex(): void
    {
        $response = $this->get($this->clientPortalUrl('/login'));

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    // BO. admin noindex.
    public function test_admin_host_is_noindex(): void
    {
        $response = $this->get($this->adminUrl('/login'));

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    // BP. MyAttorney prepared for separate identity — noindex until the
    // real product exists, distinctly worded from the FirmsVault
    // marketing page.
    public function test_myattorney_placeholder_is_noindex_and_distinctly_worded(): void
    {
        $response = $this->get($this->myAttorneyUrl('/'));

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertSee('MyAttorney', false);
    }

    public function test_baseline_security_headers_are_present_on_every_canonical_host(): void
    {
        foreach ([
            $this->marketingUrl('/'),
            $this->firmAppUrl('/login'),
            $this->clientPortalUrl('/login'),
            $this->adminUrl('/login'),
            $this->myAttorneyUrl('/'),
        ] as $url) {
            $response = $this->get($url);

            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('X-Frame-Options', 'DENY');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }

    public function test_reserved_api_host_still_carries_baseline_security_headers_even_though_it_answers_nothing(): void
    {
        $response = $this->get(app(CanonicalUrlService::class)->apiUrl().'/anything');

        $response->assertNotFound();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // Mission 1B (Extreme Security Hardening), sections 19-20.

    public function test_csp_is_present_in_report_only_mode_by_default(): void
    {
        $response = $this->get($this->firmAppUrl('/login'));

        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeader('Content-Security-Policy-Report-Only');
    }

    public function test_csp_switches_to_enforcing_when_report_only_is_disabled(): void
    {
        config(['security_headers.csp.report_only' => false]);

        $response = $this->get($this->firmAppUrl('/login'));

        $response->assertHeader('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_csp_includes_the_real_configured_directives_and_a_fresh_nonce(): void
    {
        $response = $this->get($this->firmAppUrl('/login'));

        $csp = $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString('https://cdn.plaid.com', $csp);
        $this->assertMatchesRegularExpression("/script-src[^;]*'nonce-[A-Za-z0-9]{40}'/", $csp);
    }

    public function test_csp_can_be_disabled_entirely(): void
    {
        config(['security_headers.csp.enabled' => false]);

        $response = $this->get($this->firmAppUrl('/login'));

        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_csp_is_applied_uniformly_across_every_canonical_host(): void
    {
        foreach ([
            $this->marketingUrl('/'),
            $this->firmAppUrl('/login'),
            $this->clientPortalUrl('/login'),
            $this->adminUrl('/login'),
            $this->myAttorneyUrl('/'),
        ] as $url) {
            $response = $this->get($url);

            $response->assertHeader('Content-Security-Policy-Report-Only');
        }
    }
}
