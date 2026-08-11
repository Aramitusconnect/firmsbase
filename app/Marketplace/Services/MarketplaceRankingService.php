<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\ViewModels\RankingExplanation;
use App\Marketplace\ViewModels\SearchCriteria;
use App\Marketplace\ViewModels\SearchResultView;
use Illuminate\Support\Collection;

/**
 * MarketplaceRankingService — Mission 2 (MyAttorney Marketplace
 * Core), sections 35-38. The single canonical ranking service —
 * deterministic and explainable, no opaque AI scoring (section 35),
 * no pay-to-rank (section 36: subscription/membership is never a
 * scoring input at all, deliberately absent from every weight below).
 *
 * Every input is a real, inspectable fact (practice-area match,
 * geographic distance, language match, accepting-inquiries status,
 * consultation-mode match, profile completeness, recent verification)
 * — RankingExplanation carries all of them per result so
 * SuperAdmin/developers can always answer why A outranked B (section
 * 37). Ties (including an all-zero score, e.g. an empty-criteria
 * browse) break on `id` ascending — never random, so identical input
 * always produces identical ordering (section 38's own explicit
 * requirement).
 */
class MarketplaceRankingService
{
    private const PRACTICE_AREA_MATCH_WEIGHT = 100;

    private const LANGUAGE_MATCH_WEIGHT = 20;

    private const EXACT_CITY_MATCH_WEIGHT = 15;

    private const ACCEPTING_INQUIRIES_WEIGHT = 10;

    private const CONSULTATION_MODE_MATCH_WEIGHT = 10;

    private const RECENTLY_VERIFIED_WEIGHT = 5;

    private const MAX_PROXIMITY_BONUS = 50;

    private const RECENTLY_VERIFIED_WITHIN_DAYS = 90;

    public function __construct(
        private readonly MarketplaceBadgeService $badges = new MarketplaceBadgeService,
    ) {}

    /**
     * @param  Collection<int, DirectoryFirm>  $firms
     * @return array<int, SearchResultView>
     */
    public function rank(Collection $firms, SearchCriteria $criteria): array
    {
        // Checkpoint 14 (performance hardening): one batched
        // verification query for the whole candidate set, computed
        // once here — never per-candidate inside SearchResultView
        // itself. See MarketplaceBadgeService::badgesForMany()'s own
        // docblock for the N+1 this replaces.
        $badgesByFirmId = $this->badges->badgesForMany($firms);

        $scored = $firms->map(function (DirectoryFirm $firm) use ($criteria) {
            $explanation = $this->explain($firm, $criteria);

            return ['firm' => $firm, 'explanation' => $explanation];
        });

        $sorted = $scored->sort(function (array $a, array $b) {
            $scoreComparison = $b['explanation']->totalScore <=> $a['explanation']->totalScore;

            return $scoreComparison !== 0 ? $scoreComparison : $a['firm']->id <=> $b['firm']->id;
        })->values();

        return $sorted
            ->map(fn (array $entry) => SearchResultView::fromModel($entry['firm'], $entry['explanation'], $badgesByFirmId[$entry['firm']->id] ?? []))
            ->all();
    }

    public function explain(DirectoryFirm $firm, SearchCriteria $criteria): RankingExplanation
    {
        $score = 0;

        $practiceAreaMatch = $criteria->practiceAreaSlug !== null
            && $firm->practiceAreas->contains(fn ($area) => $area->slug === $criteria->practiceAreaSlug || $area->code === $criteria->practiceAreaSlug);
        if ($practiceAreaMatch) {
            $score += self::PRACTICE_AREA_MATCH_WEIGHT;
        }

        $languageMatch = $criteria->languageCode !== null
            && $firm->languages->contains(fn ($language) => $language->code === $criteria->languageCode);
        if ($languageMatch) {
            $score += self::LANGUAGE_MATCH_WEIGHT;
        }

        $exactCityMatch = $criteria->city !== null
            && $firm->offices->contains(fn ($office) => strcasecmp($office->city, $criteria->city) === 0);
        if ($exactCityMatch) {
            $score += self::EXACT_CITY_MATCH_WEIGHT;
        }

        if ($firm->accepting_inquiries) {
            $score += self::ACCEPTING_INQUIRIES_WEIGHT;
        }

        $consultationModeMatch = $criteria->consultationMode !== null
            && in_array($criteria->consultationMode->value, $firm->consultation_modes ?? [], true);
        if ($consultationModeMatch) {
            $score += self::CONSULTATION_MODE_MATCH_WEIGHT;
        }

        $distanceMiles = $this->nearestOfficeDistanceMiles($firm, $criteria);
        if ($distanceMiles !== null) {
            $score += max(0, (int) round(self::MAX_PROXIMITY_BONUS - $distanceMiles));
        }

        $completenessScore = $firm->completeness_score;
        $score += (int) intdiv($completenessScore, 10);

        $recentlyVerified = $firm->last_verified_at !== null
            && $firm->last_verified_at->greaterThanOrEqualTo(now()->subDays(self::RECENTLY_VERIFIED_WITHIN_DAYS));
        if ($recentlyVerified) {
            $score += self::RECENTLY_VERIFIED_WEIGHT;
        }

        return new RankingExplanation(
            practiceAreaMatch: $practiceAreaMatch,
            languageMatch: $languageMatch,
            acceptingInquiries: $firm->accepting_inquiries,
            consultationModeMatch: $consultationModeMatch,
            distanceMiles: $distanceMiles,
            exactCityMatch: $exactCityMatch,
            completenessScore: $completenessScore,
            recentlyVerified: $recentlyVerified,
            totalScore: $score,
        );
    }

    private function nearestOfficeDistanceMiles(DirectoryFirm $firm, SearchCriteria $criteria): ?float
    {
        if ($criteria->originLatitude === null || $criteria->originLongitude === null) {
            return null;
        }

        $distances = $firm->offices
            ->filter(fn ($office) => $office->hasCoordinates())
            ->map(fn ($office) => $this->haversineMiles(
                $criteria->originLatitude,
                $criteria->originLongitude,
                (float) $office->latitude,
                (float) $office->longitude,
            ));

        return $distances->isEmpty() ? null : $distances->min();
    }

    private function haversineMiles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMiles = 3958.8;

        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusMiles * $c;
    }
}
