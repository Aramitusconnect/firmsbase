<?php

namespace App\Enums;

/**
 * AccountingExportBatchStatus — Blocked is set when the batch is
 * refused before a single line is built (e.g. entitlement disabled);
 * CompletedWithErrors is set when at least one line failed simulation
 * but at least one other line succeeded; Failed is reserved for a
 * batch-level failure. An empty, Completed batch with zero eligible
 * lines is valid and is not itself a failure.
 */
enum AccountingExportBatchStatus: string
{
    case Requested = 'requested';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
    case Blocked = 'blocked';
}
