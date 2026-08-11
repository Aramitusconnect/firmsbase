<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\ViewModels\PublicAttorneyProfile;
use App\Marketplace\ViewModels\PublicFirmProfile;
use App\Services\CanonicalUrlService;

/**
 * MarketplaceStructuredDataService — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 12. Builds schema.org JSON-LD for public firm/
 * attorney profile pages (schema.org's `LegalService`/`Attorney` types
 * — both LocalBusiness subtypes; the individual-attorney page uses
 * `Attorney` per schema.org's own convention for representing an
 * attorney's practice as a listing, not a bare `Person`).
 *
 * Reads exclusively from PublicFirmProfile/PublicAttorneyProfile — the
 * same "never read a raw Eloquent model from a public-facing surface"
 * rule these DTOs already enforce for the Blade views themselves — so
 * this service can never leak an internal column PublicFirmProfile
 * itself never exposed.
 *
 * Deliberately never fabricates an `aggregateRating` node — neither
 * DirectoryFirm nor DirectoryAttorney has any rating/review data
 * anywhere in this codebase, and inventing one would be exactly the
 * "misleading structured data" AddSearchIndexingHeader's own docblock
 * has always warned against. Same reasoning for `image`/`logo`: no
 * public photo-serving pipeline exists yet for DirectoryAttorney's
 * `photo_path` (DirectoryFirm has no image field at all) — omitted
 * rather than pointing at a URL that doesn't resolve.
 */
class MarketplaceStructuredDataService
{
    public function __construct(private readonly CanonicalUrlService $hosts) {}

    /**
     * @return array<string, mixed>
     */
    public function forFirm(PublicFirmProfile $profile): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'LegalService',
            'name' => $profile->displayName,
            'url' => $this->hosts->myAttorneyFirmUrl($profile->slug),
        ];

        if ($profile->description !== null) {
            $data['description'] = $profile->description;
        }

        if ($profile->phone !== null) {
            $data['telephone'] = $profile->phone;
        }

        if ($profile->website !== null) {
            $data['sameAs'] = [$profile->website];
        }

        if ($profile->foundingYear !== null) {
            $data['foundingDate'] = (string) $profile->foundingYear;
        }

        if ($profile->practiceAreaNames !== []) {
            $data['knowsAbout'] = $profile->practiceAreaNames;
        }

        if ($profile->languageNames !== []) {
            $data['availableLanguage'] = $profile->languageNames;
        }

        // Only the primary (or first published) office — schema.org's
        // LocalBusiness `address` is a single PostalAddress; a
        // multi-location firm's other offices remain fully visible in
        // the page's own "Offices" section, just not duplicated here.
        $primaryOffice = null;
        foreach ($profile->offices as $office) {
            if ($office->isPrimary) {
                $primaryOffice = $office;

                break;
            }
        }
        $primaryOffice ??= $profile->offices[0] ?? null;

        if ($primaryOffice !== null) {
            $data['address'] = [
                '@type' => 'PostalAddress',
                'streetAddress' => $primaryOffice->addressLine2 !== null
                    ? $primaryOffice->addressLine1.', '.$primaryOffice->addressLine2
                    : $primaryOffice->addressLine1,
                'addressLocality' => $primaryOffice->city,
                'addressRegion' => $primaryOffice->state,
                'postalCode' => $primaryOffice->postalCode,
                'addressCountry' => $primaryOffice->country,
            ];

            if ($primaryOffice->latitude !== null && $primaryOffice->longitude !== null) {
                $data['geo'] = [
                    '@type' => 'GeoCoordinates',
                    'latitude' => $primaryOffice->latitude,
                    'longitude' => $primaryOffice->longitude,
                ];
            }
        }

        if ($profile->attorneys !== []) {
            $data['employee'] = array_map(
                fn ($attorney) => array_filter([
                    '@type' => 'Person',
                    'name' => $attorney->name,
                    'url' => $this->hosts->myAttorneyAttorneyUrl($attorney->slug),
                    'jobTitle' => $attorney->title,
                ], fn ($value) => $value !== null),
                $profile->attorneys,
            );
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function forAttorney(PublicAttorneyProfile $profile): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Attorney',
            'name' => $profile->name,
            'url' => $this->hosts->myAttorneyAttorneyUrl($profile->slug),
        ];

        if ($profile->biography !== null) {
            $data['description'] = $profile->biography;
        }

        if ($profile->practiceAreaNames !== []) {
            $data['knowsAbout'] = $profile->practiceAreaNames;
        }

        if ($profile->languageNames !== []) {
            $data['knowsLanguage'] = $profile->languageNames;
        }

        if ($profile->firms !== []) {
            $current = array_values(array_filter($profile->firms, fn ($firm) => $firm->isCurrent));

            $data['worksFor'] = array_map(
                fn ($firm) => [
                    '@type' => 'LegalService',
                    'name' => $firm->displayName,
                    'url' => $this->hosts->myAttorneyFirmUrl($firm->slug),
                ],
                $current !== [] ? $current : $profile->firms,
            );
        }

        return $data;
    }
}
