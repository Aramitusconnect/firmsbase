<?php

declare(strict_types=1);

namespace App\Marketplace\ViewModels;

use App\Marketplace\Enums\MarketplaceBadge;
use App\Marketplace\Models\DirectoryFirm;

/**
 * SearchResultView — Mission 2 (MyAttorney Marketplace Core), section
 * 40. The result-card projection — deliberately narrower than
 * PublicFirmProfile (no full attorney roster, no biography-length
 * description). `Request Consultation`/`Secure Intake` actions are
 * NOT modeled here — section 40's own instruction: those are Mission 3
 * additions, never exposed prematurely.
 *
 * Mission 2 checkpoint 14: badges are now computed by the caller
 * (MarketplaceRankingService::rank(), via
 * MarketplaceBadgeService::badgesForMany() — one batched query for
 * the whole result set) and passed in, rather than this method
 * resolving MarketplaceBadgeService itself and querying per firm —
 * closes an N+1 on the search-results path.
 */
final readonly class SearchResultView
{
    /**
     * @param  array<int, string>  $practiceAreaNames
     * @param  array<int, string>  $languageNames
     * @param  array<int, MarketplaceBadge>  $badges
     */
    public function __construct(
        public string $slug,
        public string $displayName,
        public ?string $phone,
        public ?string $website,
        public ?string $nearestCity,
        public ?string $nearestState,
        public array $practiceAreaNames,
        public array $languageNames,
        public bool $acceptingInquiries,
        public array $badges,
        public RankingExplanation $explanation,
    ) {}

    /**
     * @param  array<int, MarketplaceBadge>  $badges
     */
    public static function fromModel(DirectoryFirm $firm, RankingExplanation $explanation, array $badges): self
    {
        $primaryOffice = $firm->offices->firstWhere('is_primary', true) ?? $firm->offices->first();

        return new self(
            slug: $firm->slug,
            displayName: $firm->display_name,
            phone: $firm->phone,
            website: $firm->website,
            nearestCity: $primaryOffice?->city,
            nearestState: $primaryOffice?->state,
            practiceAreaNames: $firm->practiceAreas->pluck('name')->all(),
            languageNames: $firm->languages->pluck('name')->all(),
            acceptingInquiries: $firm->accepting_inquiries,
            badges: $badges,
            explanation: $explanation,
        );
    }
}
