<?php

namespace App\Enums;

/**
 * PilotFeedbackStatus — pilot_feedback_items.status. No exact value
 * list given by the PDF — recommendation.
 */
enum PilotFeedbackStatus: string
{
    case New = 'new';
    case Triaged = 'triaged';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case WontFix = 'wont_fix';
    case Duplicate = 'duplicate';
}
