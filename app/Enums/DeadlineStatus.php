<?php

namespace App\Enums;

/**
 * DeadlineStatus — deadlines.status. No exact value list given by the
 * PDF — recommendation.
 */
enum DeadlineStatus: string
{
    case Upcoming = 'upcoming';
    case Due = 'due';
    case Missed = 'missed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
