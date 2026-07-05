<?php

namespace App\Enums;

enum GeneratedDocumentEventType: string
{
    case MarkedNeedsData = 'marked_needs_data';
    case MarkedReadyForReview = 'marked_ready_for_review';
    case SubmittedForAttorneyReview = 'submitted_for_attorney_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case RequestedRevision = 'requested_revision';
    case ResubmittedAfterRevision = 'resubmitted_after_revision';
    case Archived = 'archived';
}
