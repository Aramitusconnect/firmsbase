<?php

declare(strict_types=1);

namespace App\Marketplace\ViewModels;

use App\Marketplace\Enums\MarketplaceBadge;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Services\MarketplaceBadgeService;

/**
 * PublicAttorneyProfile — Mission 2 (MyAttorney Marketplace Core),
 * section 42/61/62. The ONLY shape a public Attorney-profile
 * view/template may read from. Only factual, already-populated
 * fields are exposed — section 42's own instruction not to fabricate
 * biography/credentials means this DTO never invents a value the
 * underlying model doesn't actually have; a null biography stays
 * null, the view decides whether to render the section at all.
 */
final readonly class PublicAttorneyProfile
{
    /**
     * @param  array<int, string>  $practiceAreaNames
     * @param  array<int, string>  $languageNames
     * @param  array<int, PublicFirmSummaryView>  $firms
     * @param  array<int, MarketplaceBadge>  $badges
     */
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $title,
        public ?string $biography,
        public array $practiceAreaNames,
        public array $languageNames,
        public array $firms,
        public array $badges,
    ) {}

    public static function fromModel(DirectoryAttorney $attorney): self
    {
        $attorney->loadMissing(['practiceAreas', 'languages', 'firmRelationships.firm']);

        return new self(
            slug: $attorney->slug,
            name: $attorney->name,
            title: $attorney->title,
            biography: $attorney->biography,
            practiceAreaNames: $attorney->practiceAreas->pluck('name')->all(),
            languageNames: $attorney->languages->pluck('name')->all(),
            firms: $attorney->firmRelationships
                ->map(fn ($relationship) => PublicFirmSummaryView::fromRelationship($relationship))
                ->filter()
                ->values()
                ->all(),
            badges: app(MarketplaceBadgeService::class)->badgesForAttorney($attorney),
        );
    }
}
