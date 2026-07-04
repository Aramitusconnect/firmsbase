<?php

namespace App\Enums;

/**
 * ReadinessComponentStatus — readiness_scorecard_components.status.
 * Describes the CATALOG entry's registration state (not a specific
 * matter's pass/fail result — that lives in matter_readiness_scores'
 * breakdown_json). A component can be marked Inactive without deleting
 * its row, preserving historical readiness_score_events that reference
 * it by component_key.
 */
enum ReadinessComponentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
