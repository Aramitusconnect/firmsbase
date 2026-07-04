<?php

namespace App\Enums;

/**
 * PilotFeedbackCategory — pilot_feedback_items.category. No exact
 * value list given by the PDF — recommendation.
 */
enum PilotFeedbackCategory: string
{
    case Bug = 'bug';
    case UsabilityIssue = 'usability_issue';
    case FeatureRequest = 'feature_request';
    case Praise = 'praise';
    case Other = 'other';
}
