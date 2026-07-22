<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * CursorStatus — lifecycle/health state of an `integration_sync_cursors`
 * row (Checkpoint 6, frozen-design-post-review.md §8;
 * agent-6e-sync-run-item-cursor-semantics.md §5.6/§11). Plain string
 * column, no DB-level enum type.
 *
 * `Invalid` means the PROVIDER explicitly rejected the cursor as
 * expired mid-run (e.g. a sync-token API returning "410 Gone / full
 * sync required") — never set by a client-side heuristic. Only a
 * `Repair`-type SyncRunType may claim/advance an `Invalid` cursor; an
 * `Incremental` run must refuse to claim it at all (fail closed).
 * `Failed` describes cursor HEALTH, not whether progress happened — a
 * PartialFailure run that advanced several batches still leaves
 * cursor_value updated AND status = Failed simultaneously; these are
 * not mutually exclusive.
 *
 * Mutated ONLY by the sole-writer SyncCursorService, always as part of
 * the same transaction as the batch's terminal item-status writes
 * (frozen-design-post-review.md §8's central invariant).
 */
enum CursorStatus: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Failed = 'failed';
    case Invalid = 'cursor_invalid';
}
