<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * MarketplaceIntakeEventType — the closed set of events
 * MarketplaceIntakeEvent (an append-only audit log, mirroring
 * PaymentRequestEvent) may record. Mission 3, checkpoint 1 — this
 * initial set covers the intake lifecycle itself; later checkpoints
 * (document upload, conflict review, consultation, conversion) may
 * append further cases without altering these.
 */
enum MarketplaceIntakeEventType: string
{
    case Started = 'started';
    case LinkResumed = 'link_resumed';
    case AnswersUpdated = 'answers_updated';
    case Submitted = 'submitted';
    case MarkedUnderReview = 'marked_under_review';
    case ConflictReviewRequired = 'conflict_review_required';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Converted = 'converted';
    case Abandoned = 'abandoned';
    case Expired = 'expired';

    /**
     * Mission 3, checkpoint 7 — a file was accepted for scanning
     * against this intake. Deliberately recorded at UPLOAD time, not
     * once the scan clears — the audit trail must reflect that a
     * visitor attempted an upload even if it is later rejected as
     * infected; document.metadata only ever carries the document_id,
     * never a filename or scan result (never log raw sensitive
     * content in an audit event).
     */
    case DocumentUploaded = 'document_uploaded';
}
