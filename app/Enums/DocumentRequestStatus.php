<?php

namespace App\Enums;

/**
 * DocumentRequestStatus — document_requests.status, the parent
 * request's aggregate state (distinct from each child item's own
 * DocumentRequestItemStatus). No exact value list given by the PDF —
 * recommendation. Recomputed by DocumentRequestService from its items,
 * never hand-set independently of them.
 */
enum DocumentRequestStatus: string
{
    case Open = 'open';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}
