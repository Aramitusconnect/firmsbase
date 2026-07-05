<?php

namespace App\Enums;

enum ExportJobStatus: string
{
    case Requested = 'requested';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case Cancelled = 'cancelled';
}
