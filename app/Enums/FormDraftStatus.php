<?php

namespace App\Enums;

/**
 * FormDraftStatus — form_drafts.status. Exactly the 8 approved values
 * (project rule — do not use Generated/PendingReview/ChangesRequested/
 * Superseded). The allowed transition graph between these values lives
 * in ReviewWorkflowTransitionService, not here and not re-derived by
 * any individual service:
 *   draft            -> needs_data, ready_for_review
 *   needs_data       -> ready_for_review
 *   ready_for_review -> attorney_review
 *   attorney_review  -> approved, rejected, revised
 *   revised          -> ready_for_review
 *   approved         -> archived
 *   rejected         -> archived
 *   archived         -> (terminal)
 * GeneratedDocumentStatus shares this exact same set of values and the
 * exact same transition graph (via the same
 * ReviewWorkflowTransitionService) but is kept as its own named enum
 * per approved decision, rather than merged into one shared enum.
 */
enum FormDraftStatus: string
{
    case Draft = 'draft';
    case NeedsData = 'needs_data';
    case ReadyForReview = 'ready_for_review';
    case AttorneyReview = 'attorney_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revised = 'revised';
    case Archived = 'archived';
}
