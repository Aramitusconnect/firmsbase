<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\CursorStatus;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\CursorVersionConflictException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncCursor;
use Illuminate\Support\Facades\DB;

/**
 * SyncCursorService — the ONLY writer of `integration_sync_cursors`
 * (Checkpoint 6, reviews/checkpoint-06/frozen-design-post-review.md
 * §8; agent-6e-sync-run-item-cursor-semantics.md §3-§4). Mutated
 * in-place, one row per (connection, resource_type, direction) — the
 * single most important invariant this class enforces: `cursor_value`
 * changes ONLY inside the SAME database transaction that also commits
 * the batch's terminal item-status writes (advance() must therefore be
 * called from inside the caller's own already-open batch transaction —
 * this method does not open one of its own, matching
 * IntegrationOutboxEventService::recordOnce()'s identical discipline).
 *
 * Two-layer concurrency defense: Layer 1 (partial unique index — at
 * most one non-terminal run per scope) lives on `integration_sync_runs`
 * (SyncRunService). Layer 2 — this class's `cursor_version` optimistic
 * CAS — is the detective layer underneath it; a version mismatch is
 * REJECTED, never silently serialized-and-retried (see advance()).
 */
final class SyncCursorService
{
    /**
     * Idempotent create-or-fetch for the very first write against a
     * (connection, resource_type, direction) scope. A plain
     * firstOrCreate() is safe and correct ONLY here — never for
     * ordinary advancement (see advance()) — because there is
     * genuinely nothing to protect on a first write, and the natural
     * key's own UNIQUE(firm_integration_id, resource_type,
     * sync_direction) constraint makes firstOrCreate()/createOrFirst()
     * TOCTOU-safe in this codebase's Laravel 13.8
     * (agent-6c-idempotency-concurrency.md §1/§7a).
     */
    public function firstOrCreate(FirmIntegration $connection, string $resourceType, SyncDirection $direction): IntegrationSyncCursor
    {
        return IntegrationSyncCursor::query()->firstOrCreate(
            [
                'firm_integration_id' => $connection->id,
                'resource_type' => $resourceType,
                'sync_direction' => $direction,
            ],
            [
                'firm_id' => $connection->firm_id,
                'status' => CursorStatus::Idle,
                'cursor_version' => 0,
                'consecutive_failure_count' => 0,
            ],
        );
    }

    /**
     * Atomic conditional claim (agent-6e §4.3), the direct extension of
     * IntegrationOAuthStateService::claimAndDecrypt()'s proven
     * `UPDATE ... WHERE ... RETURNING *` idiom to this table. Zero rows
     * returned means the cursor is already claimed by another run —
     * the caller must abort dispatch, never fall back to a bare read.
     */
    public function claim(int $cursorId, int $syncRunId): ?IntegrationSyncCursor
    {
        $row = DB::selectOne(
            'UPDATE integration_sync_cursors '.
            "SET status = 'running', locked_by_sync_run_id = ?, locked_at = now() ".
            "WHERE id = ? AND status != 'running' ".
            'RETURNING *',
            [$syncRunId, $cursorId]
        );

        return $row === null ? null : IntegrationSyncCursor::hydrate([(array) $row])->first();
    }

    /**
     * The cursor-advancement transaction's cursor-side half (agent-6e
     * §3). MUST be called from inside the SAME transaction as the
     * batch's terminal item-status writes — never standalone. A
     * cursor_version mismatch (0 rows affected) throws
     * CursorVersionConflictException, which the caller's own
     * transaction must let propagate so the WHOLE batch (item writes
     * included) rolls back — never caught-and-retried silently here.
     */
    public function advance(int $cursorId, int $expectedVersion, ?string $newCursorValue): IntegrationSyncCursor
    {
        $row = DB::selectOne(
            'UPDATE integration_sync_cursors '.
            "SET cursor_value = ?, cursor_version = cursor_version + 1, cursor_issued_at = now(), ".
            "status = 'idle', locked_by_sync_run_id = NULL, locked_at = NULL, consecutive_failure_count = 0 ".
            'WHERE id = ? AND cursor_version = ? '.
            'RETURNING *',
            [$newCursorValue, $cursorId, $expectedVersion]
        );

        if ($row === null) {
            throw new CursorVersionConflictException($cursorId, $expectedVersion);
        }

        return IntegrationSyncCursor::hydrate([(array) $row])->first();
    }

    /**
     * Cursor HEALTH transition when the owning run ends Failed —
     * distinct from advance(): does not touch cursor_value (the last
     * successfully-committed batch's position is preserved unchanged,
     * per agent-6e §3's structural "cursor unchanged beyond the last
     * commit" guarantee), only increments consecutive_failure_count and
     * flips status. Not a CAS — this is a health/bookkeeping update,
     * not a value-advancing one, and is safe to apply unconditionally
     * once the owning run has already reached its own terminal state.
     */
    public function markFailed(int $cursorId): ?IntegrationSyncCursor
    {
        $row = DB::selectOne(
            'UPDATE integration_sync_cursors '.
            "SET status = 'failed', locked_by_sync_run_id = NULL, locked_at = NULL, ".
            'consecutive_failure_count = consecutive_failure_count + 1 '.
            'WHERE id = ? '.
            'RETURNING *',
            [$cursorId]
        );

        return $row === null ? null : IntegrationSyncCursor::hydrate([(array) $row])->first();
    }

    /**
     * Provider-detected cursor invalidation (agent-6e §12) — cursor_value
     * is reset to NULL (reusing the existing "no successful sync yet"
     * meaning) and status flips to Invalid, in the SAME transaction as
     * the failing run's own terminal write. Only a Repair-type run may
     * subsequently claim() an Invalid cursor; an Incremental run must
     * refuse to claim/dispatch against one at all (enforced by
     * SyncRunService, not this method).
     */
    public function invalidate(int $cursorId, int $expectedVersion): IntegrationSyncCursor
    {
        $row = DB::selectOne(
            'UPDATE integration_sync_cursors '.
            "SET status = 'cursor_invalid', cursor_value = NULL, cursor_version = cursor_version + 1, ".
            'locked_by_sync_run_id = NULL, locked_at = NULL '.
            'WHERE id = ? AND cursor_version = ? '.
            'RETURNING *',
            [$cursorId, $expectedVersion]
        );

        if ($row === null) {
            throw new CursorVersionConflictException($cursorId, $expectedVersion);
        }

        return IntegrationSyncCursor::hydrate([(array) $row])->first();
    }
}
