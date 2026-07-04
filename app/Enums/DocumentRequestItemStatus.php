<?php

namespace App\Enums;

/**
 * DocumentRequestItemStatus — values taken verbatim from the master
 * plan PDF, Section 33, "Document request item" row: "requested;
 * viewed; submitted; under_review; approved; rejected;
 * needs_replacement; expired; waived". "Client reminders stop when
 * approved, waived, expired, or paused by staff" (same PDF row) — this
 * is exactly the eligibility check DocumentChaseService performs
 * before logging a chase attempt.
 */
enum DocumentRequestItemStatus: string
{
    case Requested = 'requested';
    case Viewed = 'viewed';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NeedsReplacement = 'needs_replacement';
    case Expired = 'expired';
    case Waived = 'waived';
}
