<?php

namespace App\Enums;

/**
 * FormEditionWatchStatus — form_edition_watch_items.watch_status.
 * Platform content-ops tracking only; no firm ever sees or sets these
 * (no firm_id column on that table at all).
 */
enum FormEditionWatchStatus: string
{
    case Watching = 'watching';
    case NewEditionDetected = 'new_edition_detected';
    case InReview = 'in_review';
    case Updated = 'updated';
    case NoActionNeeded = 'no_action_needed';
}
