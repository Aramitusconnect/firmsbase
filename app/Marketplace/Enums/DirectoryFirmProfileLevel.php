<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * DirectoryFirmProfileLevel — Mission 2, section 15. Exactly three
 * levels, always DERIVED from DirectoryFirm::is_claimed/
 * is_marketplace_member (see DirectoryFirm::profileLevel()) — never
 * its own independent stored column that could desync from the claim/
 * membership facts it represents.
 */
enum DirectoryFirmProfileLevel: string
{
    case PublicListing = 'public_listing';
    case ClaimedProfile = 'claimed_profile';
    case VerifiedMember = 'verified_member';
}
