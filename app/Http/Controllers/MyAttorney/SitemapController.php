<?php

declare(strict_types=1);

namespace App\Http\Controllers\MyAttorney;

use App\Http\Controllers\Controller;
use App\Marketplace\Services\MarketplaceSitemapService;
use App\Services\CanonicalUrlService;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * SitemapController — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 12. sitemap.xml is a sitemap INDEX (never a raw urlset —
 * see MarketplaceSitemapService's own docblock for why), referencing
 * a static "pages" sitemap plus one sub-sitemap per firm/attorney
 * chunk. Served regardless of whether MyAttorney indexing is
 * currently enabled (see AddSearchIndexingHeader/config/hosts.php) —
 * the sitemap URLs existing and being resolvable is harmless; the
 * X-Robots-Tag header and robots.txt (RobotsTxtController) are the
 * actual, authoritative indexing gate a crawler respects.
 */
class SitemapController extends Controller
{
    public function __construct(
        private readonly MarketplaceSitemapService $sitemap,
        private readonly CanonicalUrlService $hosts,
    ) {}

    public function index(): Response
    {
        $entries = [
            ['loc' => $this->hosts->myAttorneyUrl().'/sitemap-pages.xml'],
        ];

        for ($page = 1; $page <= $this->sitemap->firmChunkCount(); $page++) {
            $entries[] = ['loc' => $this->hosts->myAttorneyUrl()."/sitemap-firms-{$page}.xml"];
        }

        for ($page = 1; $page <= $this->sitemap->attorneyChunkCount(); $page++) {
            $entries[] = ['loc' => $this->hosts->myAttorneyUrl()."/sitemap-attorneys-{$page}.xml"];
        }

        return $this->xmlResponse($this->buildSitemapIndex($entries));
    }

    public function pages(): Response
    {
        return $this->xmlResponse($this->buildUrlSet($this->sitemap->staticPageUrls()));
    }

    public function firms(int $page): Response
    {
        if ($page < 1 || $page > max(1, $this->sitemap->firmChunkCount())) {
            throw new NotFoundHttpException;
        }

        return $this->xmlResponse($this->buildUrlSet($this->sitemap->firmUrlsForChunk($page)));
    }

    public function attorneys(int $page): Response
    {
        if ($page < 1 || $page > max(1, $this->sitemap->attorneyChunkCount())) {
            throw new NotFoundHttpException;
        }

        return $this->xmlResponse($this->buildUrlSet($this->sitemap->attorneyUrlsForChunk($page)));
    }

    /**
     * @param  array<int, array{loc: string}>  $entries
     */
    private function buildSitemapIndex(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($entries as $entry) {
            $xml .= '  <sitemap><loc>'.$this->escape($entry['loc']).'</loc></sitemap>'."\n";
        }

        $xml .= '</sitemapindex>';

        return $xml;
    }

    /**
     * @param  array<int, array{loc: string, lastmod?: ?string}>  $urls
     */
    private function buildUrlSet(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.$this->escape($url['loc']).'</loc>';

            if (! empty($url['lastmod'])) {
                $xml .= '<lastmod>'.$this->escape($url['lastmod']).'</lastmod>';
            }

            $xml .= '</url>'."\n";
        }

        $xml .= '</urlset>';

        return $xml;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xmlResponse(string $xml): Response
    {
        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
