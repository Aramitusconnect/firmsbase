<?php

namespace App\Enums;

/**
 * MatterReadinessStatus — matter_readiness_scores.status, the
 * aggregate readiness state derived from all currently-registered,
 * active components. No exact value list given by the PDF —
 * recommendation.
 */
enum MatterReadinessStatus: string
{
    case NotReady = 'not_ready';
    case PartiallyReady = 'partially_ready';
    case Ready = 'ready';
}
