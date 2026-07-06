<?php

namespace App\Enums;

/**
 * DeletionRequestStatus — approved decision #1: Phase 17 stops at
 * ReadyForExecution. There is no "Executed"/"Deleted" case here — no
 * later status exists because Phase 17 never physically deletes rows.
 */
enum DeletionRequestStatus: string
{
    case Requested = 'requested';
    case ExportClearancePending = 'export_clearance_pending';
    case RetentionClearancePending = 'retention_clearance_pending';
    case LegalHoldBlocked = 'legal_hold_blocked';
    case PendingApproval = 'pending_approval';
    case ReadyForExecution = 'ready_for_execution';
    case Denied = 'denied';
    case Cancelled = 'cancelled';
}
