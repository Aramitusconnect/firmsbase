<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * CorrectionState — Mission 2 (MyAttorney Marketplace Core), section
 * 51. Exactly the 5 canonical states the mission specifies. Approved
 * and Resolved are deliberately distinct: Approved means an admin
 * agrees the report is valid; Resolved means the actual fix has been
 * applied — never collapsed into one step.
 */
enum CorrectionState: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Resolved = 'resolved';

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::UnderReview, self::Approved], true);
    }
}
