<?php

namespace App\Http\Middleware;

use App\Services\CanonicalUrlService;
use Closure;
use Illuminate\Http\Request;

/**
 * AddSearchIndexingHeader — Mission 1 (canonical reconstruction),
 * Domain & Security Boundary Architecture, section 33. firmsvault.com
 * (marketing) is the only canonical hostname search engines should
 * index; every other surface — the authenticated Firm/Client/Admin
 * panels, and the not-yet-real MyAttorney placeholder (never publish
 * misleading MyAttorney structured data before the product exists) —
 * gets an explicit X-Robots-Tag: noindex, nofollow.
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
 * partial, or anything else a panel might return — and covers the
 * reserved api.firmsvault.com host too, for the same reason.
 *
 * Registered as a global middleware alongside AddSecurityHeaders, for
 * the same reason: Filament panel routes do not run through the `web`
 * middleware group, so this has to see every request to make the
 * marketing/non-marketing distinction in one place rather than
 * drifting across separate registrations.
 */
class AddSearchIndexingHeader
{
    public function __construct(private readonly CanonicalUrlService $canonicalUrlService) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if ($request->getHost() !== $this->canonicalUrlService->marketingHost()) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
