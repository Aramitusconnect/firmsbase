<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * MarketplaceBadge — Mission 2 (MyAttorney Marketplace Core), section
 * 19. Exactly the approved, factual badge vocabulary — deliberately
 * excludes vague marketing language ("Verified & Trusted", "Best
 * Lawyer", "Recommended Lawyer", "Top Attorney", "Guaranteed") per
 * that section's explicit instruction. `GoogleConnected` is reserved
 * vocabulary only (section 19: "later/optional") — never resolved or
 * displayed until a real Google enrichment connection exists.
 */
enum MarketplaceBadge: string
{
    case PublicListing = 'public_listing';
    case ClaimedProfile = 'claimed_profile';
    case FirmAuthorityVerified = 'firm_authority_verified';
    case AttorneyIdentityVerified = 'attorney_identity_verified';
    case FirmsVaultMember = 'firmsvault_member';

    // Reserved vocabulary only (section 19) — never resolved/displayed today.
    case GoogleConnected = 'google_connected';

    public function label(): string
    {
        return match ($this) {
            self::PublicListing => 'Public Listing',
            self::ClaimedProfile => 'Claimed Profile',
            self::FirmAuthorityVerified => 'Firm Authority Verified',
            self::AttorneyIdentityVerified => 'Attorney Identity Verified',
            self::FirmsVaultMember => 'FirmsVault Member',
            self::GoogleConnected => 'Google Connected',
        };
    }
}
