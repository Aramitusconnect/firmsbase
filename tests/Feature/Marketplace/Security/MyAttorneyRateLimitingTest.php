<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Security;

use App\Marketplace\Models\DirectoryFirm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * MyAttorneyRateLimitingTest — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 14 (security hardening). Covers the hardening
 * test matrix items BA-BE: every public MyAttorney route was
 * completely unthrottled before this checkpoint — an unauthenticated,
 * DB-querying surface with no rate limit is a real scraping/
 * compute-amplification target (see MarketplaceSearchService's own
 * MAX_CANDIDATES docblock and MarketplaceRankingPerformanceTest for
 * the specific amplification this closes off). Mirrors
 * InboundWebhookRateLimitTest's own established "loop past the limit,
 * assert the last request 429s" idiom for the one full end-to-end
 * proof, and a lighter static route-middleware check for the
 * remaining routes (same underlying Laravel throttle mechanism,
 * already proven end-to-end by the first test — no need to loop 60-120
 * times per route).
 */
final class MyAttorneyRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Same reasoning as InboundWebhookRateLimitTest's own setUp():
        // the array cache store persists in-process across every test
        // in the same run, and throttle keys on source IP.
        Cache::flush();
    }

    // BA. Home/search route is throttled end-to-end.
    public function test_the_61st_home_page_request_within_a_minute_returns_429(): void
    {
        $lastStatus = null;

        for ($i = 1; $i <= 61; $i++) {
            $response = $this->get($this->myAttorneyUrl('/'));
            $lastStatus = $response->getStatusCode();

            if ($i < 61) {
                $this->assertNotSame(429, $lastStatus, "Request #{$i} must not be rate-limited yet.");
            }
        }

        $this->assertSame(429, $lastStatus, 'The 61st home-page request within the same minute must be rate-limited.');
    }

    // BB. Firm profile route carries the same throttle.
    public function test_firm_profile_route_carries_a_throttle_middleware(): void
    {
        $route = Route::getRoutes()->getByName('myattorney.firms.show');

        $this->assertNotNull($route);
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
    }

    // BC. Attorney profile route carries the same throttle.
    public function test_attorney_profile_route_carries_a_throttle_middleware(): void
    {
        $route = Route::getRoutes()->getByName('myattorney.attorneys.show');

        $this->assertNotNull($route);
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
    }

    // BD. Sitemap routes carry a throttle (bots/crawlers get a more
    // generous, but still real, cap).
    public function test_sitemap_routes_carry_a_throttle_middleware(): void
    {
        foreach (['myattorney.sitemap.index', 'myattorney.sitemap.pages', 'myattorney.sitemap.firms', 'myattorney.sitemap.attorneys'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route {$name} must exist.");
            $this->assertContains('throttle:120,1', $route->gatherMiddleware(), "Route {$name} must carry a throttle middleware.");
        }
    }

    // BE. robots.txt carries a throttle. Route::getRoutes() has two
    // /robots.txt registrations (the global domain-less one and the
    // myattorney-scoped one that wins on this host — see routes/web.php's
    // own docblock) — find the one actually bound to the myattorney host.
    public function test_robots_txt_route_carries_a_throttle_middleware(): void
    {
        $this->get($this->myAttorneyUrl('/robots.txt'))->assertOk();

        $myAttorneyRobots = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'robots.txt' && $route->getDomain() !== null);

        $this->assertNotNull($myAttorneyRobots);
        $this->assertContains('throttle:120,1', $myAttorneyRobots->gatherMiddleware());
    }

    // Proves the throttle does not fire early for genuine, varied
    // real-user browsing (different published listings) — the limit
    // is a real cap, not an overly aggressive one that breaks normal
    // use.
    public function test_normal_browsing_across_several_listings_never_gets_rate_limited(): void
    {
        DirectoryFirm::factory()->count(5)->create();

        foreach (DirectoryFirm::query()->pluck('slug') as $slug) {
            $this->get($this->myAttorneyUrl('/firms/'.$slug))->assertOk();
        }

        $this->get($this->myAttorneyUrl('/'))->assertOk();
    }
}
