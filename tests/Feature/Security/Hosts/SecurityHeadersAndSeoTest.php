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
}
