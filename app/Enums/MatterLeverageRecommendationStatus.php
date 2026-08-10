<?php

namespace App\Enums;

/**
 * MatterLeverageRecommendationStatus — Leverage Ratio Optimizer, item
 * 24. Lifecycle of a matter_leverage_recommendations row. Never
 * deleted — Dismissed/Resolved/Stale are terminal-for-now states, not
 * erasures; full history (created_at/acknowledged_by/acknowledged_at/
 * dismissed_by/dismissed_at) is preserved on the row itself.
 */
enum MatterLeverageRecommendationStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Dismissed = 'dismissed';
    case Resolved = 'resolved';
    case Stale = 'stale';
}
