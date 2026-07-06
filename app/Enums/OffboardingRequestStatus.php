<?php

namespace App\Enums;

enum OffboardingRequestStatus: string
{
    case Requested = 'requested';
    case ExportPending = 'export_pending';
    case ExportCompleted = 'export_completed';
    case RetentionClearancePending = 'retention_clearance_pending';
    case RetentionCleared = 'retention_cleared';
    case LegalHoldBlocked = 'legal_hold_blocked';
    case ReadyForDeletion = 'ready_for_deletion';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
