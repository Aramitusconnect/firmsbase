<?php

namespace App\Enums;

enum ImportAuditEventType: string
{
    case BatchCreated = 'batch_created';
    case MappingSaved = 'mapping_saved';
    case DryRunExecuted = 'dry_run_executed';
    case ValidationRun = 'validation_run';
    case DuplicateDetected = 'duplicate_detected';
    case BatchConfirmed = 'batch_confirmed';
    case RowApplied = 'row_applied';
    case ApplyCompleted = 'apply_completed';
    case RollbackCompleted = 'rollback_completed';
    case BatchCancelled = 'batch_cancelled';
}
