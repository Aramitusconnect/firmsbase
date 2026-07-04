<?php

namespace App\Enums;

/**
 * PilotFeedbackSource — pilot_feedback_items.source. Values taken
 * directly from the master plan's own wording: "firm/client/internal
 * source."
 */
enum PilotFeedbackSource: string
{
    case Firm = 'firm';
    case Client = 'client';
    case Internal = 'internal';
}
