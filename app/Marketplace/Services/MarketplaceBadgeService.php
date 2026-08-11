<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\MarketplaceBadge;
use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use Illuminate\Support\Collection;

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
     * Mission 2 checkpoint 14 (performance hardening). Batch
     * counterpart to badgesFor() — one verification query for the
     * whole collection instead of one per firm. Exists specifically
     * for MarketplaceRankingService::rank(): building a search-result
     * list previously called badgesFor() (and therefore
     * MarketplaceVerificationService::isVerified()) once per
     * candidate, each issuing its own directory_verifications query —
     * a real N+1 on the one path (broad search results) where N can
     * be large. Single-record callers (profile pages, the Admin
     * DirectoryFirmResource view, the Firm self-service profile page)
     * are unaffected — badgesFor() itself is untouched.
     *
     * @param  Collection<int, DirectoryFirm>  $firms
     * @return array<int, array<int, MarketplaceBadge>> keyed by firm id
     */
    public function badgesForMany(Collection $firms): array
    {
        $verifiedIds = $this->verification->verifiedIdsAmong(
            DirectoryFirm::class,
            $firms->pluck('id')->all(),
            VerificationDimension::FirmAuthority,
        );

        return $firms->mapWithKeys(function (DirectoryFirm $firm) use ($verifiedIds) {
            $badges = [$firm->is_claimed ? MarketplaceBadge::ClaimedProfile : MarketplaceBadge::PublicListing];

            if ($firm->is_marketplace_member) {
                $badges[] = MarketplaceBadge::FirmsVaultMember;
            }

            if (isset($verifiedIds[$firm->id])) {
                $badges[] = MarketplaceBadge::FirmAuthorityVerified;
            }

            return [$firm->id => $badges];
        })->all();
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
