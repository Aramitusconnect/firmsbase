<?php

namespace App\Enums;

enum ImplementationProjectStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Completed = 'completed';
}
