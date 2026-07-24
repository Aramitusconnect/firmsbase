# Runbook: Cursor Conflict

## Symptom

A sync batch fails with `CursorVersionConflictException` — the cursor's optimistic-concurrency check found the cursor had already been advanced or reset by another actor since the batch began.

## Real source involved

`SyncCursorService::advance()` (the CAS: `UPDATE integration_sync_cursors SET ... WHERE cursor_version = ? RETURNING *`), `CursorVersionConflictException`, `SyncCursorService::isCursorSafeBatch()`.

## Diagnosis

This exception is thrown from **inside the same database transaction** that also wrote the batch's item-terminal-status rows — so the whole batch rolls back together, not just the cursor update. The frozen design decision is **reject, never automatically serialize-and-retry**. This most commonly happens when two sync triggers for the same connection overlap (though `SyncRunAlreadyInProgressException` should normally prevent full-run overlap — see [sync-failures.md](sync-failures.md)) or when a cursor was manually reset mid-batch.

Cursor-safety: only batches composed entirely of `Succeeded`/`Skipped`/`FailedPermanent` items are eligible to advance the cursor past them (`isCursorSafeBatch()`) — `Pending`/`Retrying`/`FailedRetryable` items block advancement. A conflict is therefore about *concurrent* cursor mutation, not about which items within the batch are or aren't terminal.

## Required role

Platform-plane investigation: SupportAgent+ with active support-access session, or SuperAdmin/PlatformAdmin/ImplementationSpecialist. This is not a firm-user-actionable scenario directly — the batch's own automatic rollback and the next scheduled run are the normal recovery path.

## Approved interface

No dedicated operator tool exists to manually resolve or force-advance a cursor. The normal recovery is the framework's own retry of the next sync run, which will re-read the cursor's current (post-conflict) value and proceed from there.

## Steps

1. Confirm the exception is genuinely `CursorVersionConflictException` (transaction-level rollback, whole batch retried on next run) and not a downstream symptom of something else.
2. Check for overlapping triggers (e.g. a scheduled sync run and a manual nudge close together) as the most common benign explanation.
3. If overlaps are ruled out and conflicts recur repeatedly for the same connection, this may indicate the cursor is being mutated from an unexpected code path — escalate to engineering rather than attempting a manual database fix.
4. Allow the next scheduled/nudged sync run to proceed naturally — the rolled-back batch's work is not lost, it will be re-attempted against the cursor's current state.

## Prohibited actions

Never manually update `integration_sync_cursors.cursor_version` or the cursor value directly in the database to "resolve" a conflict — this bypasses the optimistic-concurrency mechanism entirely and could cause silently skipped or duplicated data on the next sync.

## Evidence to capture

Firm id, connection id, timestamp, whether an overlapping trigger explains it, recurrence frequency for this connection.

## Escalation condition

Recurring conflicts (more than an isolated one-off) for the same connection, with no overlapping-trigger explanation, should be escalated to engineering for investigation into whether something outside the normal sync-run path is mutating the cursor.

## Recovery verification

The next sync run for the connection completes without a repeat `CursorVersionConflictException`, and the cursor advances normally.
