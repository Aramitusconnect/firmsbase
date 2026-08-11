<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RobotsTxtTest — Mission 2 (MyAttorney Marketplace Core), checkpoint
 * 12. RobotsTxtController is host-aware and reads the same
 * CanonicalUrlService::myAttorneyIndexingEnabled() flag
 * AddSearchIndexingHeader itself reads — proves the two mechanisms
 * never disagree with each other.
 */
class RobotsTxtTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_host_robots_txt_is_permissive(): void
    {
        $response = $this->get($this->marketingUrl('/robots.txt'));

        $response->assertOk();
        $response->assertSee('Disallow:', false);
        $response->assertDontSee('Disallow: /', false);
    }

    public function test_authenticated_app_hosts_disallow_everything(): void
    {
        foreach ([$this->firmAppUrl('/robots.txt'), $this->clientPortalUrl('/robots.txt'), $this->adminUrl('/robots.txt')] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee('Disallow: /', false);
        }
    }

    public function test_myattorney_host_disallows_everything_and_has_no_sitemap_line_by_default(): void
    {
        config(['hosts.myattorney_indexing_enabled' => false]);

        $response = $this->get($this->myAttorneyUrl('/robots.txt'));

        $response->assertOk();
        $response->assertSee('Disallow: /', false);
        $response->assertDontSee('Sitemap:', false);
    }

    public function test_myattorney_host_is_permissive_with_a_sitemap_line_once_indexing_is_enabled(): void
    {
        config(['hosts.myattorney_indexing_enabled' => true]);

        $response = $this->get($this->myAttorneyUrl('/robots.txt'));

        $response->assertOk();
        $response->assertDontSee('Disallow: /', false);
        $response->assertSee('Sitemap: '.$this->myAttorneyUrl('/sitemap.xml'), false);
    }
}
