<?php

declare(strict_types=1);

namespace App\Http\Controllers\MyAttorney;

use App\Http\Controllers\Controller;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Services\MarketplaceStructuredDataService;
use App\Marketplace\ViewModels\PublicAttorneyProfile;
use App\Services\CanonicalUrlService;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * AttorneyProfileController — Mission 2 (MyAttorney Marketplace
 * Core), section 42. `/attorneys/{slug}`. Same explicit-slug-lookup
 * rationale as FirmProfileController (DirectoryAttorney's own
 * HasPublicUuid already claims the uuid route key).
 */
class AttorneyProfileController extends Controller
{
    public function show(string $slug, CanonicalUrlService $hosts, MarketplaceStructuredDataService $structuredData): View
    {
        $attorney = DirectoryAttorney::query()->where('slug', $slug)->first();

        if ($attorney === null || ! $attorney->isPubliclyVisible()) {
            throw new NotFoundHttpException;
        }

        $profile = PublicAttorneyProfile::fromModel($attorney);
        $canonicalUrl = $hosts->myAttorneyAttorneyUrl($profile->slug);
        $description = $profile->biography !== null
            ? Str::limit(strip_tags($profile->biography), 155)
            : $profile->name.' — Attorney profile on MyAttorney by FirmsVault.';

        return view('myattorney.attorneys.show', [
            'profile' => $profile,
            'canonicalUrl' => $canonicalUrl,
            'og' => [
                'title' => $profile->name.' | MyAttorney by FirmsVault',
                'description' => $description,
                'url' => $canonicalUrl,
            ],
            'structuredData' => $structuredData->forAttorney($profile),
        ]);
    }
}
