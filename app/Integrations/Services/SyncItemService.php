<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Models\IntegrationSyncItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * SyncItemService — the ONLY writer of `integration_sync_items`
 * (Checkpoint 6, reviews/checkpoint-06/frozen-design-post-review.md
 * §3/§6/§8; agent-6c-idempotency-concurrency.md §9;
 * agent-6e-sync-run-item-cursor-semantics.md §5.5/§10). Status is
 * mutated ONLY through this class — never directly on the model from
 * any other caller (mirrors ProviderConnectionService::transitionStatus()'s
 * sole-writer precedent).
 */
final class SyncItemService
{
    private const TERMINAL_STATUSES = [
        SyncItemStatus::Succeeded,
        SyncItemStatus::FailedPermanent,
        SyncItemStatus::Skipped,
    ];

    /**
     * The owning run's own batch-loop write path — first-attempt
     * processing (Pending -> {Succeeded, FailedRetryable,
     * FailedPermanent, Skipped}). A raw INSERT ... ON CONFLICT (sync_run_id,
     * external_id) DO UPDATE SET attempt_count = attempt_count + 1, ...
     * (agent-6c §9) — Laravel's fluent upsert() cannot express the
     * atomic increment. Reprocessing the SAME object within one run
     * (pagination overlap, a retried page fetch) legitimately updates
     * state rather than silently discarding the retry attempt.
     *
     * When $externalId is null, ON CONFLICT never matches (Postgres
     * treats NULL as non-equal to NULL for uniqueness purposes) — each
     * call inserts a genuinely new row, which is correct: an item with
     * no external_id yet has nothing to deduplicate against.
     */
    public function recordAttempt(
        int $firmId,
        int $syncRunId,
        string $resourceType,
        ?string $localType,
        ?int $localId,
        ?string $externalId,
        SyncItemStatus $status,
        ?string $payloadHash = null,
        ?string $lastError = null,
        ?string $nextAttemptAt = null,
    ): IntegrationSyncItem {
        $terminalAt = in_array($status, self::TERMINAL_STATUSES, true) ? 'now()' : 'NULL';

        $row = DB::selectOne(
            'INSERT INTO integration_sync_items '.
            '(firm_id, sync_run_id, resource_type, local_type, local_id, external_id, status, '.
            'attempt_count, next_attempt_at, payload_hash, last_error, terminal_at, created_at, updated_at) '.
            "VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, {$terminalAt}, now(), now()) ".
            'ON CONFLICT (sync_run_id, external_id) DO UPDATE SET '.
            'attempt_count = integration_sync_items.attempt_count + 1, '.
            'status = EXCLUDED.status, '.
            'next_attempt_at = EXCLUDED.next_attempt_at, '.
            'payload_hash = EXCLUDED.payload_hash, '.
            'last_error = EXCLUDED.last_error, '.
            "terminal_at = {$terminalAt}, ".
            'updated_at = now() '.
            'RETURNING *',
            [
                $firmId, $syncRunId, $resourceType, $localType, $localId, $externalId, $status->value,
                $nextAttemptAt, $payloadHash, $lastError,
            ]
        );

        return IntegrationSyncItem::hydrate([(array) $row])->first();
    }

    /**
     * The Checkpoint 8 retry poller's atomic claim shape
     * (FailedRetryable -> Retrying), ready for that future poller to
     * call — Checkpoint 6 ships this primitive without building the
     * poller itself. Same guarded-UPDATE-RETURNING discipline as every
     * other claim in this checkpoint.
     *
     * CHECKPOINT 8 PREREQUISITE FIX (agent-8h-architecture-security-review.md
     * §0/§2 item 0) — now() -> statement_timestamp(): now() is frozen at
     * the wrapping transaction's start (TenantContextService::
     * runWithFirmContext() always opens a real DB::transaction()), so a
     * row whose next_attempt_at becomes due after the transaction opened
     * but before this statement runs would be missed under the old
     * predicate — the identical bug class fixed for
     * IntegrationOutboxEventService::claim() in commit 9196d30.
     * statement_timestamp() is live per-statement, never frozen by how
     * long the wrapping transaction has been open.
     */
    public function claimForRetry(int $itemId): ?IntegrationSyncItem
    {
        $row = DB::selectOne(
            'UPDATE integration_sync_items '.
            "SET status = 'retrying' ".
            "WHERE id = ? AND status = 'failed_retryable' AND next_attempt_at <= statement_timestamp() ".
            'RETURNING *',
            [$itemId]
        );

        return $row === null ? null : IntegrationSyncItem::hydrate([(array) $row])->first();
    }

    /**
     * Resolves a claimed retry (Retrying -> {Succeeded, FailedRetryable,
     * FailedPermanent}) — never Skipped, per agent-6e §10's illegal-
     * transition table (an item that has already been attempted at
     * least once can no longer be "deliberately never attempted").
     */
    public function resolveRetryOutcome(
        int $itemId,
        SyncItemStatus $outcome,
        ?string $nextAttemptAt = null,
        ?string $lastError = null,
    ): ?IntegrationSyncItem {
        if ($outcome === SyncItemStatus::Skipped || $outcome === SyncItemStatus::Pending || $outcome === SyncItemStatus::Retrying) {
            throw new InvalidArgumentException("resolveRetryOutcome() cannot transition a retrying item to {$outcome->value}.");
        }

        $terminalAt = in_array($outcome, self::TERMINAL_STATUSES, true) ? 'now()' : 'NULL';

        $row = DB::selectOne(
            'UPDATE integration_sync_items '.
            "SET status = ?, next_attempt_at = ?, last_error = ?, terminal_at = {$terminalAt}, ".
            'attempt_count = attempt_count + 1 '.
            "WHERE id = ? AND status = 'retrying' ".
            'RETURNING *',
            [$outcome->value, $nextAttemptAt, $lastError, $itemId]
        );

        return $row === null ? null : IntegrationSyncItem::hydrate([(array) $row])->first();
    }
}
