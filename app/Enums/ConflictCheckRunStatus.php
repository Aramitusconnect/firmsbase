<?php

namespace App\Enums;

/**
 * ConflictCheckRunStatus — conflict_check_runs.status. Not given exact
 * values by the master plan; this is my proposed value set, flagged as
 * a recommendation (approved during Phase 2 planning).
 */
enum ConflictCheckRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
