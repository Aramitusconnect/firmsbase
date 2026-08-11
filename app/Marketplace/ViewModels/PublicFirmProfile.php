<?php

declare(strict_types=1);

namespace App\Marketplace\ViewModels;

use App\Marketplace\Enums\DirectoryFirmProfileLevel;
use App\Marketplace\Enums\MarketplaceBadge;
use App\Marketplace\Enums\MarketplaceCapability;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceBadgeService;
use App\Marketplace\Services\MarketplaceCapabilityService;

/**
 * PublicFirmProfile — Mission 2 (MyAttorney Marketplace Core), section
 * 41/61/62. The ONLY shape a public Firm-profile view/template may
 * read from. Never pass a raw `DirectoryFirm` Eloquent model into a
 * public Blade view — internal columns (id, firm_id linking to a
 * tenant Firm, source_reference, completeness_score, raw timestamps)
 * never reach this DTO, let alone a template.
 */
final readonly class PublicFirmProfile
{
    /**
     * @param  array<int, PublicOfficeView>  $offices
     * @param  array<int, string>  $practiceAreaNames
     * @param  array<int, string>  $languageNames
     * @param  array<int, PublicAttorneySummaryView>  $attorneys
     * @param  array<int, MarketplaceBadge>  $badges
     * @param  array<int, MarketplaceCapability>  $capabilities
     */
    public function __construct(
        public string $slug,
        public string $displayName,
        public ?string $description,
        public ?string $phone,
        public ?string $website,
        public ?int $foundingYear,
        public bool $acceptingInquiries,
        public array $consultationModes,
        public DirectoryFirmProfileLevel $profileLevel,
        public array $badges,
        public array $capabilities,
        public array $offices,
        public array $practiceAreaNames,
        public array $languageNames,
        public array $attorneys,
    ) {}

    public static function fromModel(DirectoryFirm $firm): self
    {
        $firm->loadMissing(['offices', 'practiceAreas', 'languages', 'attorneyRelationships.attorney']);

        return new self(
            slug: $firm->slug,
            displayName: $firm->display_name,
            description: $firm->description,
            phone: $firm->phone,
            website: $firm->website,
            foundingYear: $firm->founding_year,
            acceptingInquiries: $firm->accepting_inquiries,
            consultationModes: $firm->consultation_modes ?? [],
            profileLevel: $firm->profileLevel(),
            badges: app(MarketplaceBadgeService::class)->badgesFor($firm),
            capabilities: app(MarketplaceCapabilityService::class)->capabilitiesFor($firm),
            offices: $firm->offices
                ->filter(fn ($office) => $office->published)
                ->map(fn ($office) => PublicOfficeView::fromModel($office))
                ->values()
                ->all(),
            practiceAreaNames: $firm->practiceAreas->pluck('name')->all(),
            languageNames: $firm->languages->pluck('name')->all(),
            attorneys: $firm->attorneyRelationships
                ->map(fn ($relationship) => PublicAttorneySummaryView::fromRelationship($relationship))
                ->filter()
                ->values()
                ->all(),
        );
    }
}
