<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * HealthSummaryState — the derived, persisted, firm-facing health
 * summary for a `firm_integrations` connection (Checkpoint 8,
 * agent-8f-health-state-design.md §2). Distinct from, and never merged
 * with, the two vocabularies that already exist:
 *  - App\Integrations\Enums\ConnectionStatus — the connection's
 *    LIFECYCLE (is there a live, authorized, in-scope credential at
 *    all). Sole writer: ProviderConnectionService.
 *  - App\Integrations\Enums\HealthStatus — the RAW, per-attempt result
 *    of one health-check call or operational signal
 *    (SupportsHealthCheckContract::checkHealth()'s return vocabulary).
 *    Never itself persisted as a column.
 *
 * This 4-case enum is the DERIVED summary — a strict, deterministic
 * function of ConnectionStatus + the accumulated signal columns on
 * `integration_connection_health` (App\Integrations\Services\HealthStateService::computeSummaryState()).
 * It is NEVER independently settable by any caller — there is no
 * public setter for it, only the five record*() methods on
 * HealthStateService, each of which recomputes it as their final step.
 */
enum HealthSummaryState: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case ActionRequired = 'action_required';
    case Unavailable = 'unavailable';
}
