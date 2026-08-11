<?php

namespace App\Http\Middleware;

use App\Services\CanonicalUrlService;
use Closure;
use Illuminate\Http\Request;

/**
 * AddSearchIndexingHeader — Mission 1 (canonical reconstruction),
 * Domain & Security Boundary Architecture, section 33. firmsvault.com
 * (marketing) is always indexable; every authenticated surface
 * (Firm/Client/Admin panels, the reserved API host) always gets an
 * explicit X-Robots-Tag: noindex, nofollow.
 *
 * MyAttorney (Mission 2, checkpoint 12) is a third case: real public
 * content now exists (firm/attorney profile pages, a sitemap,
 * structured data — see MarketplaceSitemapService/
 * MarketplaceStructuredDataService), but Mission 1C's own boundary
 * (SAFE_TO_BUILD_MYATTORNEY = YES, SAFE_TO_LAUNCH_MYATTORNEY_PUBLICLY
 * = NO) means this middleware must not unilaterally start telling
 * search engines to index it. Whether myAttorneyHost() is indexable is
 * therefore config-gated —
 * CanonicalUrlService::myAttorneyIndexingEnabled() — defaulting off
 * everywhere (including production) until an owner deliberately sets
 * MYATTORNEY_PUBLIC_INDEXING_ENABLED=true in that environment's real
 * configuration. See config/hosts.php's own docblock for the full
 * rationale.
 *
 * The canonical branch had exactly one prior noindex artifact — a
 * <meta name="robots" content="noindex, nofollow"> tag scoped to the
 * single public-payment-page layout — and public/robots.txt is
 * currently a permissive "allow everything." This middleware is the
 * first systematic, per-host SEO boundary in the codebase; it does not
 * remove or duplicate that existing meta tag (harmless overlap on the
 * one page that already had it).
 *
 * A response header, not a <meta> tag, so it applies uniformly
 * regardless of whether the response is an HTML page, a Livewire
 * partial, or anything else a panel might return.
 *
 * Registered as a global middleware alongside AddSecurityHeaders, for
 * the same reason: Filament panel routes do not run through the `web`
 * middleware group, so this has to see every request to make the
 * indexable/non-indexable distinction in one place rather than
 * drifting across separate registrations.
 */
class AddSearchIndexingHeader
{
    public function __construct(private readonly CanonicalUrlService $canonicalUrlService) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (! in_array($request->getHost(), $this->indexableHosts(), true)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /**
     * @return array<int, string>
     */
    private function indexableHosts(): array
    {
        $hosts = [$this->canonicalUrlService->marketingHost()];

        if ($this->canonicalUrlService->myAttorneyIndexingEnabled()) {
            $hosts[] = $this->canonicalUrlService->myAttorneyHost();
        }

        return $hosts;
    }
}
