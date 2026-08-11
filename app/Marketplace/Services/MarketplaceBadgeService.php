<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\MarketplaceBadge;
use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;

/**
 * MarketplaceBadgeService — Mission 2 (MyAttorney Marketplace Core),
 * section 19. The single place a badge is resolved for display. Never
 * hardcode a badge string in a Blade/Livewire view — always call
 * `badgesFor()`/`badgesForAttorney()` here, so the exact, approved
 * factual vocabulary (`App\Marketplace\Enums\MarketplaceBadge`) is
 * the only thing that can ever be shown.
 *
 * `FirmAuthorityVerified`/`AttorneyIdentityVerified` now read the real
 * `directory_verifications` table (Mission 2 checkpoint 7) via
 * MarketplaceVerificationService::isVerified() — never inferred from
 * `is_claimed` alone (section 19: claiming and verification are
 * distinct badges, deliberately not implying one another).
 */
class MarketplaceBadgeService
{
    public function __construct(
        private readonly MarketplaceVerificationService $verification = new MarketplaceVerificationService,
    ) {}

    /**
     * @return array<int, MarketplaceBadge>
     */
    public function badgesFor(DirectoryFirm $firm): array
    {
        $badges = [$firm->is_claimed ? MarketplaceBadge::ClaimedProfile : MarketplaceBadge::PublicListing];

        if ($firm->is_marketplace_member) {
            $badges[] = MarketplaceBadge::FirmsVaultMember;
        }

        if ($this->verification->isVerified($firm, VerificationDimension::FirmAuthority)) {
            $badges[] = MarketplaceBadge::FirmAuthorityVerified;
        }

        return $badges;
    }

    /**
     * @return array<int, MarketplaceBadge>
     */
    public function badgesForAttorney(DirectoryAttorney $attorney): array
    {
        $badges = [];

        if ($this->verification->isVerified($attorney, VerificationDimension::AttorneyIdentity)) {
            $badges[] = MarketplaceBadge::AttorneyIdentityVerified;
        }

        return $badges;
    }
}
