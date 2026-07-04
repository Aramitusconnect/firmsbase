<?php

namespace App\Enums;

/**
 * BackupRestoreTestStatus — backup_restore_tests.status. No exact
 * value list given by the PDF — recommendation.
 */
enum BackupRestoreTestStatus: string
{
    case InProgress = 'in_progress';
    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
