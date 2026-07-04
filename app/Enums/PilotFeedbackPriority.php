<?php

namespace App\Enums;

/**
 * PilotFeedbackPriority — pilot_feedback_items.priority. The PDF's
 * Scope text mentions "severity/priority" as one combined idea, not
 * two separate fields — this single enum covers both rather than
 * creating two near-duplicate enums (PilotFeedbackSeverity would have
 * been redundant with this). No exact value list given by the PDF —
 * recommendation.
 */
enum PilotFeedbackPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
