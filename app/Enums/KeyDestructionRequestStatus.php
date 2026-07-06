<?php

namespace App\Enums;

enum KeyDestructionRequestStatus: string
{
    case Requested = 'requested';
    case ExportClearancePending = 'export_clearance_pending';
    case RetentionClearancePending = 'retention_clearance_pending';
    case LegalHoldBlocked = 'legal_hold_blocked';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Executed = 'executed';
    case Denied = 'denied';
    case Cancelled = 'cancelled';
}
