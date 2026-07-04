<?php

namespace App\Enums;

enum ActivationChecklistStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
