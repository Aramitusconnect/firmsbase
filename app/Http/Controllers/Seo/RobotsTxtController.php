<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Services\CanonicalUrlService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * RobotsTxtController — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 12. Replaces the previously-static, fully-permissive
 * `public/robots.txt` (identical content on every canonical host,
 * with no Sitemap: directive and no awareness that six distinct
 * hostnames share one Laravel app) with a host-aware route, matching
 * AddSearchIndexingHeader's own per-host boundary rather than
 * contradicting it — every authenticated surface (Firm/Client/Admin/
 * API) is disallowed here exactly as it is noindexed there, and
 * MyAttorney's own Allow/Disallow + Sitemap: line is driven by the
 * SAME CanonicalUrlService::myAttorneyIndexingEnabled() flag, so the
 * two mechanisms can never drift out of sync with each other.
 *
 * Registered with no Route::domain() constraint for five of the six
 * canonical hosts (see routes/web.php); the MyAttorney host also
 * registers this same controller explicitly ahead of its own
 * domain-scoped catch-all route, which would otherwise win the match
 * — see routes/web.php's own docblock for why.
 */
class RobotsTxtController extends Controller
{
    public function __invoke(Request $request, CanonicalUrlService $hosts): Response
    {
        $host = $request->getHost();

        $body = match (true) {
            $host === $hosts->marketingHost() => $this->permissive(),
            $host === $hosts->myAttorneyHost() && $hosts->myAttorneyIndexingEnabled() => $this->permissive()
                ."\nSitemap: {$hosts->myAttorneyUrl()}/sitemap.xml\n",
            default => $this->disallowAll(),
        };

        return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    private function permissive(): string
    {
        return "User-agent: *\nDisallow:\n";
    }

    private function disallowAll(): string
    {
        return "User-agent: *\nDisallow: /\n";
    }
}
