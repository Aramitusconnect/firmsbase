<?php

namespace App\Enums;

/**
 * MaintenanceWindowStatus — maintenance_windows.status. No exact
 * value list given by the PDF — recommendation. Rescheduled marks a
 * window that was cancelled in favor of a NEW window row (see
 * MaintenanceWindowService::reschedule() — mirrors Phase 3's
 * PaymentPlan renegotiate()-creates-a-new-version pattern), never a
 * mutation of the original window's own schedule.
 */
enum MaintenanceWindowStatus: string
{
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';
}
