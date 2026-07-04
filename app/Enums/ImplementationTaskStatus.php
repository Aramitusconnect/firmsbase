<?php

namespace App\Enums;

enum ImplementationTaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Blocked = 'blocked';
}
