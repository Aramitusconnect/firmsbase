<?php

namespace App\Enums;

/**
 * DegradedBehavior — integration_degradation_modes.degraded_behavior.
 * The declared behavior for an integration type when it is unavailable
 * or disallowed in a private environment. Declaration-only in Phase 16
 * — no code path currently branches on this value at a real call site.
 */
enum DegradedBehavior: string
{
    case QueueAndRetry = 'queue_and_retry';
    case BlockAction = 'block_action';
    case FallbackLocal = 'fallback_local';
    case DisableFeature = 'disable_feature';
}
