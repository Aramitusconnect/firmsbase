<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * VerificationState — Mission 2 (MyAttorney Marketplace Core), section
 * 24's own "state" field, per dimension. Deliberately smaller than
 * ClaimState (no Disputed/EvidenceRequired) — verification is a
 * direct SuperAdmin action taken after reviewing evidence, not a
 * public-facing multi-party workflow like a claim.
 */
enum VerificationState: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
