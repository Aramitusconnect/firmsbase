<?php

namespace App\Enums;

/**
 * GeneratedDocumentStatus — generated_documents.status. Exactly the
 * same 8 approved values and the same transition graph as
 * FormDraftStatus (see that enum's docblock), both enforced through
 * the single shared ReviewWorkflowTransitionService — kept as a
 * separate named enum per approved decision rather than merged.
 */
enum GeneratedDocumentStatus: string
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
