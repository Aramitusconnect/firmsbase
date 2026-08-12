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

    /**
     * Mission 3, checkpoint 8 — a Firm-triggered conflict evaluation
     * against this intake's own captured opposing-party names found at
     * least one possible match against the firm's existing clients/
     * contacts/parties/matter parties. Metadata carries only
     * possible_match_count — never the matched entity's name/type/id,
     * which is confidential existing-client data a marketplace_intake_events
     * row must never leak (this event type has no distinct visibility
     * boundary from every other intake event, so it must stay generic).
     */
    case ConflictReviewRequired = 'conflict_review_required';

    /**
     * Mission 3, checkpoint 8 — a Firm reviewer has manually confirmed
     * the flagged possible matches are not a real conflict (or resolved
     * them by whatever means, outside this codebase's own scope — no
     * ConflictCheckResult exists yet to resolve at intake time, since
     * no Party/Matter rows exist yet either) and returned the intake to
     * UnderReview so the normal accept/decline workflow (checkpoint 10)
     * can proceed.
     */
    case ConflictReviewCleared = 'conflict_review_cleared';
}
