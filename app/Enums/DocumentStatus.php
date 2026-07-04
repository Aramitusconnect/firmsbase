<?php

namespace App\Enums;

/**
 * DocumentStatus — documents.status. No exact value list given by the
 * master plan (unlike DocumentRequestItemStatus, which has an explicit
 * Section 33 row) — this is a recommendation. Distinct from
 * DocumentScanStatus: a document can be Uploaded and awaiting virus
 * scan simultaneously; scan outcome (clean/infected/failed) is tracked
 * separately and gates whether an Uploaded document may ever become
 * Approved/usable.
 */
enum DocumentStatus: string
{
    case Uploaded = 'uploaded';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NeedsReplacement = 'needs_replacement';
    case Replaced = 'replaced';
    case Expired = 'expired';
}
