<?php

declare(strict_types=1);

namespace App\Http\Controllers\MyAttorney;

use App\Http\Controllers\Controller;
use App\Marketplace\Enums\DirectoryFirmProfileLevel;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\ViewModels\PublicFirmProfile;
use App\Services\CanonicalUrlService;
use Illuminate\View\View;
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
 */
class FirmProfileController extends Controller
{
    public function show(string $slug, CanonicalUrlService $hosts): View
    {
        $firm = DirectoryFirm::query()->where('slug', $slug)->first();

        if ($firm === null || ! $firm->isPubliclyVisible()) {
            throw new NotFoundHttpException;
        }

        $profile = PublicFirmProfile::fromModel($firm);

        return view('myattorney.firms.show', [
            'profile' => $profile,
            // Checkpoint 6: the claim entry point is the authenticated
            // Firm app (app.firmsvault.com), never a MyAttorney-hosted
            // form — section 60/63. Only shown for an unclaimed listing.
            'claimUrl' => $profile->profileLevel === DirectoryFirmProfileLevel::PublicListing
                ? $hosts->firmAppUrl().'/myattorney-claim?firm='.urlencode($profile->slug)
                : null,
        ]);
    }
}
