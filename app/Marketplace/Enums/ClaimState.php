<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * ClaimState — Mission 2 (MyAttorney Marketplace Core), section 20.
 * The exact 8-value lifecycle the mission specifies — no additional
 * states, no collapsed states.
 */
enum ClaimState: string
{
    case Pending = 'pending';
    case EvidenceRequired = 'evidence_required';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
    case Disputed = 'disputed';
    case Expired = 'expired';

    /**
     * Still in the pre-decision pipeline — eligible for
     * approve()/reject(), and the set checked for duplicate/conflicting
     * claim detection.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::EvidenceRequired, self::UnderReview, self::Disputed], true);
    }

    public function isDecided(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Revoked, self::Expired], true);
    }
}
