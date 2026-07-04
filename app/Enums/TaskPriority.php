<?php

namespace App\Enums;

/**
 * TaskPriority — tasks.priority. No exact value list given by the
 * PDF — recommendation.
 */
enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
