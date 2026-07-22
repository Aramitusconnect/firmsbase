<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * SyncItemStatus — lifecycle state of an `integration_sync_items` row
 * (Checkpoint 6, frozen-design-post-review.md §8;
 * agent-6e-sync-run-item-cursor-semantics.md §5.5/§10/§7). Plain
 * string column, no DB-level enum type.
 *
 * Terminal states: Succeeded, FailedPermanent, Skipped. `Retrying` is
 * the atomic-claim state the future Checkpoint 8 retry poller uses
 * (mirrors Pending vs. Running at the run level) — first-attempt
 * processing (inside the owning run's own batch loop, already
 * serialized by the run-level claim) never passes through it; only the
 * independently-dispatched retry path does.
 *
 * Cursor-safety (which statuses block `integration_sync_cursors`
 * advancement past a batch, agent-6e §7): Pending/Retrying/
 * FailedRetryable BLOCK; Succeeded/Skipped/FailedPermanent do NOT —
 * see SyncCursorService::isCursorSafeBatch().
 *
 * Mutated ONLY by the owning run's own batch loop (first attempts) or
 * a dedicated retry-claim path — never directly by any other caller.
 */
enum SyncItemStatus: string
{
    case Pending = 'pending';
    case Retrying = 'retrying';
    case Succeeded = 'succeeded';
    case FailedRetryable = 'failed_retryable';
    case FailedPermanent = 'failed_permanent';
    case Skipped = 'skipped';
}
