<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Services\CanonicalUrlService;

/**
 * MarketplaceSitemapService — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 12. The only place sitemap URL sets are computed
 * — SitemapController reads exclusively from this service, never
 * queries DirectoryFirm/DirectoryAttorney directly.
 *
 * Chunked by design (CHUNK_SIZE well under the sitemap protocol's own
 * 50,000-URL/50MB-per-file cap) from day one — this is a public,
 * unboundedly-growing directory, so a single flat sitemap.xml is not
 * a "good enough for V1" shortcut the way some of this mission's
 * other deliberate V1 simplifications are; it would silently break
 * once the marketplace crosses the protocol limit. sitemap.xml itself
 * is a sitemap INDEX (schema.org sitemapindex), never a raw urlset,
 * for the same reason.
 *
 * Only Published firms/attorneys are ever included — the exact same
 * isPubliclyVisible() rule FirmProfileController/AttorneyProfileController
 * already 404 against, so a sitemap entry and a live page always agree.
 *
 * lastmod on each chunk's own <sitemap> index entry is approximate
 * (the time the index was built, not each row's real max updated_at)
 * — acceptable per the sitemap protocol (lastmod is informational, not
 * required to be exact) and avoids an extra aggregate query per chunk
 * purely for index-entry cosmetics.
 */
class MarketplaceSitemapService
{
    private const int CHUNK_SIZE = 5000;

    public function __construct(private readonly CanonicalUrlService $hosts) {}

    public function firmChunkCount(): int
    {
        return (int) ceil($this->publishedFirmCount() / self::CHUNK_SIZE);
    }

    public function attorneyChunkCount(): int
    {
        return (int) ceil($this->publishedAttorneyCount() / self::CHUNK_SIZE);
    }

    public function publishedFirmCount(): int
    {
        return DirectoryFirm::query()->where('publication_state', DirectoryPublicationState::Published)->count();
    }

    public function publishedAttorneyCount(): int
    {
        return DirectoryAttorney::query()->where('publication_state', DirectoryPublicationState::Published)->count();
    }

    /**
     * @return array<int, array{loc: string, lastmod: ?string}>
     */
    public function firmUrlsForChunk(int $page): array
    {
        return DirectoryFirm::query()
            ->where('publication_state', DirectoryPublicationState::Published)
            ->orderBy('id')
            ->forPage($page, self::CHUNK_SIZE)
            ->get(['slug', 'updated_at'])
            ->map(fn (DirectoryFirm $firm) => [
                'loc' => $this->hosts->myAttorneyFirmUrl($firm->slug),
                'lastmod' => $firm->updated_at?->toAtomString(),
            ])
            ->all();
    }

    /**
     * @return array<int, array{loc: string, lastmod: ?string}>
     */
    public function attorneyUrlsForChunk(int $page): array
    {
        return DirectoryAttorney::query()
            ->where('publication_state', DirectoryPublicationState::Published)
            ->orderBy('id')
            ->forPage($page, self::CHUNK_SIZE)
            ->get(['slug', 'updated_at'])
            ->map(fn (DirectoryAttorney $attorney) => [
                'loc' => $this->hosts->myAttorneyAttorneyUrl($attorney->slug),
                'lastmod' => $attorney->updated_at?->toAtomString(),
            ])
            ->all();
    }

    /**
     * The bare set of always-present, non-listing pages — just the
     * home/search page today (section 45: no browse-by-practice-area/
     * city index pages exist to enumerate here).
     *
     * @return array<int, array{loc: string, lastmod: null}>
     */
    public function staticPageUrls(): array
    {
        return [
            ['loc' => $this->hosts->myAttorneyUrl().'/', 'lastmod' => null],
        ];
    }
}
