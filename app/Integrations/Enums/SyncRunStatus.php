<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * SyncRunStatus — lifecycle state of an `integration_sync_runs` row
 * (Checkpoint 6, frozen-design-post-review.md §8;
 * agent-6e-sync-run-item-cursor-semantics.md §5.4/§9). Plain string
 * column, no DB-level enum type — mirrors ConnectionStatus/
 * firm_integrations.status's exact convention.
 *
 * Terminal states: Succeeded, PartialFailure, Failed, Cancelled — none
 * ever transitions back to Running or Pending; a new IntegrationSyncRun
 * row is always created for further work. `Pending` exists (vs.
 * collapsing straight to Running) because every run is dispatched
 * async, creating a real, observable gap between "row created" and "a
 * worker actually began executing it" — Checkpoint 8's future sweep
 * needs to tell a stuck-Pending run apart from a stuck-Running one. No
 * `Cancelling` state exists; cancellation-in-progress is represented
 * by the nullable `cancel_requested_at` timestamp instead (mirrors
 * firm_integrations using `disconnected_at` rather than a
 * `Disconnecting` status).
 *
 * Mutated ONLY by the sole-writer SyncRunService, mirroring
 * ProviderConnectionService::transitionStatus()'s precedent — never by
 * any Filament action, job, or controller directly.
 */
enum SyncRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case PartialFailure = 'partial_failure';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
