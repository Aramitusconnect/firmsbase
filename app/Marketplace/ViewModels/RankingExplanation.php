<?php

declare(strict_types=1);

namespace App\Marketplace\ViewModels;

/**
 * RankingExplanation — Mission 2 (MyAttorney Marketplace Core),
 * section 37. Every scored result carries one of these so
 * SuperAdmin/developers can always answer "why did result A rank
 * above result B" — never an opaque number alone. The numeric weights
 * are internal/debugging detail (not necessarily shown to the public
 * visitor), but the underlying facts they're built from (practice
 * match, distance, language, accepting inquiries, freshness) are
 * always real and inspectable.
 */
final readonly class RankingExplanation
{
    public function __construct(
        public bool $practiceAreaMatch,
        public bool $languageMatch,
        public bool $acceptingInquiries,
        public bool $consultationModeMatch,
        public ?float $distanceMiles,
        public bool $exactCityMatch,
        public int $completenessScore,
        public bool $recentlyVerified,
        public int $totalScore,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'practice_area_match' => $this->practiceAreaMatch,
            'language_match' => $this->languageMatch,
            'accepting_inquiries' => $this->acceptingInquiries,
            'consultation_mode_match' => $this->consultationModeMatch,
            'distance_miles' => $this->distanceMiles,
            'exact_city_match' => $this->exactCityMatch,
            'completeness_score' => $this->completenessScore,
            'recently_verified' => $this->recentlyVerified,
            'total_score' => $this->totalScore,
        ];
    }
}
