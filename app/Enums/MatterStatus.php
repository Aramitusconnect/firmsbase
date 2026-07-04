<?php

namespace App\Enums;

/**
 * MatterStatus — matters.status. Canonical values from the master
 * plan's workflow state-machine table (Section 33, "Matter" row).
 * "filed/submitted where applicable" is represented here as
 * FiledSubmitted — not every practice area/matter type reaches this
 * state, which is fine; it simply won't be used by packs that don't
 * need it.
 *
 * Matter opening REQUIRES a completed, non-blocking conflict check —
 * enforced by MatterOpeningService, not by this enum or any database
 * constraint. A matter must not reach `open` any other way.
 */
enum MatterStatus: string
{
    case Draft = 'draft';
    case ConflictCheckRequired = 'conflict_check_required';
    case ConflictReview = 'conflict_review';
    case Open = 'open';
    case Active = 'active';
    case WaitingOnClient = 'waiting_on_client';
    case ReadyForReview = 'ready_for_review';
    case FiledSubmitted = 'filed_submitted';
    case Closed = 'closed';
    case Archived = 'archived';
}
