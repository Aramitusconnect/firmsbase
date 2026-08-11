<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\ViewModels\SearchCriteria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * MarketplaceSearchService — Mission 2 (MyAttorney Marketplace Core),
 * sections 32-34. Queries FirmsBase's own directory database only —
 * no Google Places dependency (section 32). Prefers plain indexed
 * Postgres queries over an external search cluster (section 33: "do
 * not create an expensive external search cluster unless scale
 * actually requires it" — V1 is Michigan-only with a bounded row
 * count).
 *
 * Returns unranked candidates only — MarketplaceRankingService is the
 * single place scoring/ordering happens (section 35's own "one
 * canonical ranking service").
 *
 * Normalization scope (section 33): name/city matching is
 * case-insensitive via the lowercased name_normalized/city_normalized
 * columns. Punctuation-difference and common-abbreviation
 * normalization ("O'Brien" matching "OBrien", "St." matching
 * "Street") and practice-area synonym matching (practice_areas.synonyms
 * exists in the schema but is not yet consulted here) are deliberately
 * deferred — disclosed, not silently missing — rather than building a
 * fuzzy-matching layer speculatively ahead of real search-quality data.
 */
class MarketplaceSearchService
{
    public function candidates(SearchCriteria $criteria): Collection
    {
        $query = DirectoryFirm::query()
            ->where('publication_state', DirectoryPublicationState::Published)
            ->with(['offices', 'practiceAreas', 'languages']);

        if ($criteria->name !== null) {
            $normalized = Str::lower($criteria->name);
            $query->where(function (Builder $q) use ($normalized) {
                $q->where('name_normalized', 'like', "%{$normalized}%")
                    ->orWhereHas('attorneyRelationships.attorney', function (Builder $attorneyQuery) use ($normalized) {
                        $attorneyQuery->where('name_normalized', 'like', "%{$normalized}%");
                    });
            });
        }

        if ($criteria->practiceAreaSlug !== null) {
            $query->whereHas('practiceAreas', function (Builder $q) use ($criteria) {
                $q->where('slug', $criteria->practiceAreaSlug)
                    ->orWhere('code', $criteria->practiceAreaSlug);
            });
        }

        if ($criteria->city !== null) {
            $normalizedCity = Str::lower($criteria->city);
            $query->whereHas('offices', function (Builder $q) use ($normalizedCity) {
                $q->where('city_normalized', 'like', "%{$normalizedCity}%")->where('published', true);
            });
        }

        if ($criteria->state !== null) {
            $query->whereHas('offices', function (Builder $q) use ($criteria) {
                $q->where('state', $criteria->state)->where('published', true);
            });
        }

        if ($criteria->postalCode !== null) {
            $query->whereHas('offices', function (Builder $q) use ($criteria) {
                $q->where('postal_code', $criteria->postalCode)->where('published', true);
            });
        }

        if ($criteria->languageCode !== null) {
            $query->whereHas('languages', function (Builder $q) use ($criteria) {
                $q->where('code', $criteria->languageCode);
            });
        }

        if ($criteria->acceptingInquiriesOnly) {
            $query->where('accepting_inquiries', true);
        }

        if ($criteria->consultationMode !== null) {
            $query->whereJsonContains('consultation_modes', $criteria->consultationMode->value);
        }

        // distinct() — a firm matching via multiple attorney/office/
        // practice-area relations must never be returned twice
        // (section 85 AH: "duplicate result prevented").
        return $query->distinct()->get();
    }
}
