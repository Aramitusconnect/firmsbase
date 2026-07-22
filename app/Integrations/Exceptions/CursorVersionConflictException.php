<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * CursorVersionConflictException — thrown by
 * SyncCursorService::advance() when the optimistic-concurrency CAS
 * (`UPDATE integration_sync_cursors SET ... WHERE cursor_version = ?
 * RETURNING *`) affects zero rows — some other actor already advanced
 * or reset the cursor since this batch's transaction began reading it
 * (frozen-design-post-review.md §8; agent-6e-sync-run-item-cursor-
 * semantics.md §4.2).
 *
 * The frozen decision is REJECT, never serialize-and-retry
 * automatically: thrown from inside the SAME database transaction that
 * also wrote the batch's item-terminal-status rows, so the caller's
 * DB::transaction() callback re-throwing this exception rolls back the
 * WHOLE batch — item writes included, not merely the cursor write. The
 * owning SyncRunService catches this and terminates the run
 * `SyncRunStatus::Failed` with `error_summary = 'cursor_version_conflict'`.
 * Reconciliation is left to the NEXT run, which reads the cursor's
 * current, now-confirmed state fresh.
 */
final class CursorVersionConflictException extends RuntimeException
{
    public function __construct(public readonly int $cursorId, public readonly int $expectedVersion)
    {
        parent::__construct(
            "Cursor {$cursorId} could not be advanced: expected cursor_version={$expectedVersion} did not match ".
            'the row\'s current version. Another actor advanced or reset this cursor concurrently; the whole '.
            'batch transaction must be rolled back, not retried silently.'
        );
    }
}
