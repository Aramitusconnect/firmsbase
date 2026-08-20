<?php

declare(strict_types=1);

namespace App\Http\Controllers\MyAttorney;

use App\Http\Controllers\Controller;
use App\Marketplace\Enums\DirectoryFirmProfileLevel;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceAnalyticsService;
use App\Marketplace\Services\MarketplaceStructuredDataService;
use App\Marketplace\ViewModels\PublicFirmProfile;
use App\Services\CanonicalUrlService;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FirmProfileController — Mission 2 (MyAttorney Marketplace Core),
 * section 41. `/firms/{slug}`. Deliberately does NOT use implicit
 * route-model binding (DirectoryFirm's own `HasPublicUuid` trait
 * already claims `getRouteKeyName()` for internal/API uuid lookups) —
 * an explicit slug lookup here keeps the public SEO URL and the
 * internal opaque identifier fully independent, per section 43.
 *
 * A non-existent OR non-publicly-visible (draft/suspended/removed/
 * archived) slug 404s identically — never distinguishes "doesn't
 * exist" from "exists but hidden" in the response.
 *
 * NOT shared-cacheable, despite being a public SEO page: the "Start Secure
 * Intake" form embeds a per-visitor CSRF token, and a shared cache that
 * stored one visitor's token and served it to another would break every
 * subsequent submission (and hand out a token minted for someone else's
 * session). Search engines still index it — no-store is a caching
 * instruction, not a robots directive.
 */
class FirmProfileController extends Controller
{
    public function show(string $slug, CanonicalUrlService $hosts, MarketplaceStructuredDataService $structuredData, MarketplaceAnalyticsService $analytics): Response
    {
        $firm = DirectoryFirm::query()->where('slug', $slug)->first();

        if ($firm === null || ! $firm->isPubliclyVisible()) {
            throw new NotFoundHttpException;
        }

        $analytics->recordFirmProfileView($firm);

        $profile = PublicFirmProfile::fromModel($firm);
        $canonicalUrl = $hosts->myAttorneyFirmUrl($profile->slug);
        $description = $profile->description !== null
            ? Str::limit(strip_tags($profile->description), 155)
            : $profile->displayName.' — Firm profile on MyAttorney by FirmsVault.';

        return response()->view('myattorney.firms.show', [
            'profile' => $profile,
            // Checkpoint 6: the claim entry point is the authenticated
            // Firm app (app.firmsvault.com), never a MyAttorney-hosted
            // form — section 60/63. Only shown for an unclaimed listing.
            'claimUrl' => $profile->profileLevel === DirectoryFirmProfileLevel::PublicListing
                ? $hosts->firmAppUrl().'/myattorney-claim?firm='.urlencode($profile->slug)
                : null,
            'canonicalUrl' => $canonicalUrl,
            'og' => [
                'title' => $profile->displayName.' | MyAttorney by FirmsVault',
                'description' => $description,
                'url' => $canonicalUrl,
            ],
            'structuredData' => $structuredData->forFirm($profile),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
