<?php

namespace App\Enums;

/**
 * FormReviewEventType — form_review_events.event_type. Named after the
 * transitions in ReviewWorkflowTransitionService's graph, not the old
 * rejected status names.
 */
enum FormReviewEventType: string
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
