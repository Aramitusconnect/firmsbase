<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\CursorStatus;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\CursorVersionConflictException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncCursor;
use App\Services\EmailBodyEncryptionService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
 *
 * Checkpoint 2 (FirmsVault Live Integrations, Microsoft 365 provider —
 * checkpoint2-design-sync-webhooks.md §1.2; checkpoint2-combined-design.md
 * §2 P-14) addition: `cursor_value` is now encrypted at rest, per-firm,
 * via the SAME `EmailBodyEncryptionService` every other sensitive
 * Integration-domain string already uses (IntegrationCredentialService,
 * WebhookConnectionResolverService) — no second encryption system.
 * Encryption/decryption happens ONLY here and in advance()'s one caller
 * (PullSyncJob) — SupportsPullSyncContract/SupportsIncrementalSyncContract
 * are unmodified; every provider (including Microsoft's) continues to
 * deal exclusively in plaintext cursor strings.
 */
final class SyncCursorService
{
    public function __construct(private readonly EmailBodyEncryptionService $encryption) {}

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
     * returned means the cursor is already claimed by a LIVE run — the
     * caller must abort dispatch, never fall back to a bare read.
     *
     * CHECKPOINT 8.2 (§A6) — CLAIM LEASE. The `status != 'running'`
     * predicate alone was safe only because `PullSyncJob` used to run its
     * whole body, provider calls included, inside ONE transaction: a
     * crashed worker's claim rolled back with everything else, so a
     * `running` cursor always meant a genuinely live run.
     *
     * That transaction has been removed (it held `FOR UPDATE` on
     * `firm_integrations` across the provider HTTP call — the exact shape
     * Checkpoint 8.1 proved deadlocks durable writes), so the claim now
     * COMMITS immediately. Without a lease, a worker killed mid-run would
     * leave the cursor `running` forever and no future pull could ever
     * claim it again — trading a deadlock for a permanent stall.
     *
     * A claim is therefore also grantable when the existing one is
     * provably abandoned: `locked_at` older than
     * `config('integrations.sync_cursors.claim_lease_seconds')`. That
     * default is deliberately several times `PullSyncJob::$timeout`, so a
     * lease can never lapse while its owner is still legitimately working
     * — the queue worker kills the job long before. Takeover is still one
     * atomic compare-and-set, so two workers can never both win, and
     * `locked_by_sync_run_id` records which run owns it.
     */
    public function claim(int $cursorId, int $syncRunId): ?IntegrationSyncCursor
    {
        $leaseSeconds = (int) config('integrations.sync_cursors.claim_lease_seconds', 1800);

        $row = DB::selectOne(
            'UPDATE integration_sync_cursors '.
            "SET status = 'running', locked_by_sync_run_id = ?, locked_at = now() ".
            'WHERE id = ? AND ('.
            "status != 'running' ".
            'OR locked_at IS NULL '.
            'OR locked_at <= ?'.
            ') '.
            'RETURNING *',
            [$syncRunId, $cursorId, now()->subSeconds($leaseSeconds)]
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
     *
     * Checkpoint 2 addition (checkpoint2-design-sync-webhooks.md §1.2;
     * checkpoint2-combined-design.md §2 P-14): gains a required
     * `FirmIntegration $connection` parameter, needed because
     * `EmailBodyEncryptionService::encrypt()` is keyed per-firm, not
     * per-cursor. When `$newCursorValue !== null`, it is encrypted here
     * (never by the caller) and both `cursor_value`/
     * `cursor_value_encryption_key_id` are bound into the SAME `SET`
     * clause as the rest of this method's existing atomic UPDATE — the
     * two columns are never written by separate statements. When
     * `$newCursorValue === null`, both columns are set NULL together
     * (the CHECK constraint added by this checkpoint's migration,
     * `integration_sync_cursors_value_key_id_pair`, enforces this
     * invariant at the database layer too).
     */
    public function advance(FirmIntegration $connection, int $cursorId, int $expectedVersion, ?string $newCursorValue): IntegrationSyncCursor
    {
        $ciphertext = null;
        $encryptionKeyId = null;

        if ($newCursorValue !== null) {
            $result = $this->encryption->encrypt($connection->firm, $newCursorValue);

            if (! $result->succeeded) {
                throw new RuntimeException("Cannot advance sync cursor {$cursorId}: {$result->reason}");
            }

            $ciphertext = $result->ciphertext;
            $encryptionKeyId = $result->encryptionKeyId;
        }

        $row = DB::selectOne(
            'UPDATE integration_sync_cursors '.
            'SET cursor_value = ?, cursor_value_encryption_key_id = ?, cursor_version = cursor_version + 1, cursor_issued_at = now(), '.
            "status = 'idle', locked_by_sync_run_id = NULL, locked_at = NULL, consecutive_failure_count = 0 ".
            'WHERE id = ? AND cursor_version = ? '.
            'RETURNING *',
            [$ciphertext, $encryptionKeyId, $cursorId, $expectedVersion]
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
     *
     * Checkpoint 2 REQUIRED correction (security review Finding 5, P1;
     * checkpoint2-combined-design.md §2 P-14): this method's UPDATE
     * statement ALSO sets `cursor_value_encryption_key_id = NULL` in the
     * SAME statement as `cursor_value = NULL`. Without this, the
     * `integration_sync_cursors_value_key_id_pair` CHECK constraint
     * (added by this checkpoint's migration) would reject this UPDATE
     * outright on every cursor that has previously advanced at least
     * once — i.e. on every real-world invalidation, including the
     * Microsoft `410 Gone` self-healing path (§1.4) this method exists
     * to serve — throwing a raw QueryException instead of cleanly
     * invalidating the cursor. Both columns are reset together, exactly
     * mirroring advance()'s own "never one without the other" discipline
     * for the `$newCursorValue === null` case above.
     */
    public function invalidate(int $cursorId, int $expectedVersion): IntegrationSyncCursor
    {
        $row = DB::selectOne(
            'UPDATE integration_sync_cursors '.
            "SET status = 'cursor_invalid', cursor_value = NULL, cursor_value_encryption_key_id = NULL, ".
            'cursor_version = cursor_version + 1, '.
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

    /**
     * Checkpoint 2 addition (checkpoint2-design-sync-webhooks.md §1.2;
     * checkpoint2-combined-design.md §2 P-14). The decrypt-side
     * counterpart to advance()'s encrypt-on-write — returns `null`
     * immediately if `$cursor->cursor_value === null` (a fresh or
     * invalidated cursor; no decrypt attempted, matching
     * `firstOrCreate()`/`invalidate()`'s "both columns NULL together"
     * invariant). Otherwise delegates to
     * `EmailBodyEncryptionService::decrypt()`, which fails closed exactly
     * as `IntegrationCredentialService::decryptForOperation()` already
     * lives with (throws if the referenced TenantEncryptionKey is no
     * longer Active — a disclosed, pre-existing limitation, not
     * something this checkpoint is positioned to fix).
     */
    public function decryptCursorValue(FirmIntegration $connection, IntegrationSyncCursor $cursor): ?string
    {
        if ($cursor->cursor_value === null) {
            return null;
        }

        return $this->encryption->decrypt(
            $connection->firm,
            $cursor->cursor_value,
            (int) $cursor->cursor_value_encryption_key_id,
        );
    }
}
