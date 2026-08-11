<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\MarketplaceBadge;
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
 * `FirmAuthorityVerified`/`AttorneyIdentityVerified` are deliberately
 * isolated to their own private methods, always `false` today — no
 * `directory_verifications` table exists until Mission 2 checkpoint 7.
 * Checkpoint 7 changes exactly these two methods, not every call site
 * that renders a badge.
 */
class MarketplaceBadgeService
{
    /**
     * @return array<int, MarketplaceBadge>
     */
    public function badgesFor(DirectoryFirm $firm): array
    {
        $badges = [$firm->is_claimed ? MarketplaceBadge::ClaimedProfile : MarketplaceBadge::PublicListing];

        if ($firm->is_marketplace_member) {
            $badges[] = MarketplaceBadge::FirmsVaultMember;
        }

        if ($this->hasVerifiedFirmAuthority($firm)) {
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

        if ($this->hasVerifiedAttorneyIdentity($attorney)) {
            $badges[] = MarketplaceBadge::AttorneyIdentityVerified;
        }

        return $badges;
    }

    /**
     * Mission 2 checkpoint 7 (verification model) placeholder.
     */
    private function hasVerifiedFirmAuthority(DirectoryFirm $firm): bool
    {
        return false;
    }

    /**
     * Mission 2 checkpoint 7 (verification model) placeholder.
     */
    private function hasVerifiedAttorneyIdentity(DirectoryAttorney $attorney): bool
    {
        return false;
    }
}
