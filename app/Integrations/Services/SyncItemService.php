<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Models\IntegrationSyncItem;
use App\Models\Firm;
use App\Services\TimelineEventRecorder;
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

    public function __construct(
        private readonly TimelineEventRecorder $events,
        private readonly IntegrationRequeueAuditLogger $requeueAudit,
    ) {
    }

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

        $item = IntegrationSyncItem::hydrate([(array) $row])->first();

        if ($status === SyncItemStatus::FailedPermanent) {
            $this->recordRetryExhaustedEvent($item);
        }

        return $item;
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

        if ($row === null) {
            return null;
        }

        $item = IntegrationSyncItem::hydrate([(array) $row])->first();

        if ($outcome === SyncItemStatus::FailedPermanent) {
            $this->recordRetryExhaustedEvent($item);
        }

        return $item;
    }

    /**
     * Checkpoint 9 addition (frozen design §3):
     * `integration_sync.item_retry_exhausted` fires on transition INTO
     * FailedPermanent only — covers both write paths that can produce
     * that status (first-attempt processing via recordAttempt(), and
     * the retry-claim path via resolveRetryOutcome()), since this
     * table's own docblock states status is mutated ONLY through this
     * class via those two paths.
     */
    private function recordRetryExhaustedEvent(IntegrationSyncItem $item): void
    {
        $firm = Firm::query()->find($item->firm_id);

        if ($firm === null) {
            return;
        }

        $this->events->record($firm, 'integration_sync.item_retry_exhausted', $item, null, [
            'sync_item_id' => $item->id,
            'sync_run_id' => $item->sync_run_id,
            'resource_type' => $item->resource_type,
            'external_id' => $item->external_id,
            'attempt_count' => $item->attempt_count,
        ]);
    }

    /**
     * Requeue a permanently-failed sync item (Checkpoint 9, frozen
     * design §7; agent-9e-requeue-governance.md §6/§8). A single
     * guarded UPDATE, never check-then-write. Guard order: firm
     * ownership -> correct terminal status (failed_permanent) -> not
     * superseded by a later run's item for the same external_id already
     * having succeeded -> connection not disconnected -> credential not
     * revoked.
     *
     * Actor authority is checked by the CALLER before invocation
     * (`IntegrationAccessPolicyService::assertCanConfigure()`, per
     * frozen design §7/§9), never inside this guarded SQL.
     * $actorFirmUserId is accepted purely as evidence to record.
     *
     * Re-transitions the row to `failed_retryable` unconditionally
     * (subject to the guards above) rather than attempting to encode
     * "is the structural blocker that originally produced
     * FailedPermanent still true" inside this single-row SQL guard
     * (agent-9e §6 item 4) — that question can only be answered by
     * `SyncRetryPollJob`'s own next poll cycle, which already
     * re-discovers and re-dead-letters a structurally-blocked item
     * cheaply if the blocker still applies. `attempt_count` is left
     * untouched (no ceiling column exists on this table; eligibility is
     * governed by `status` alone, exactly as for first-pass
     * processing). `terminal_at` IS actively cleared to NULL — unlike
     * `last_error`, which is left untouched — because a `failed_retryable`
     * item must not appear to a future retention sweep as if it were
     * still in its terminal window.
     */
    public function requeueFromFailedPermanent(
        int $itemId,
        int $firmId,
        string $reasonCode,
        ?int $actorFirmUserId = null,
    ): ?IntegrationSyncItem {
        $row = DB::selectOne(
            'UPDATE integration_sync_items item '.
            "SET status = 'failed_retryable', ".
            'next_attempt_at = statement_timestamp(), '.
            'terminal_at = NULL, '.
            'requeue_count = item.requeue_count + 1, '.
            'requeued_at = statement_timestamp(), '.
            'updated_at = statement_timestamp() '.
            'FROM integration_sync_runs self_run '.
            'WHERE item.id = ? '.
            '  AND item.firm_id = ? '.
            '  AND item.sync_run_id = self_run.id '.
            "  AND item.status = 'failed_permanent' ".
            '  AND NOT EXISTS ('.
            '    SELECT 1 FROM integration_sync_items newer '.
            '    JOIN integration_sync_runs newer_run ON newer_run.id = newer.sync_run_id '.
            '    WHERE newer.firm_id = item.firm_id '.
            '      AND item.external_id IS NOT NULL '.
            '      AND newer.external_id = item.external_id '.
            '      AND newer.id <> item.id '.
            "      AND newer.status = 'succeeded' ".
            '      AND newer_run.created_at > self_run.created_at'.
            '  ) '.
            '  AND EXISTS ('.
            '    SELECT 1 FROM firm_integrations fi '.
            "    WHERE fi.id = self_run.firm_integration_id AND fi.status <> 'disconnected'".
            '  ) '.
            '  AND EXISTS ('.
            '    SELECT 1 FROM integration_credentials ic '.
            "    WHERE ic.firm_integration_id = self_run.firm_integration_id AND ic.status = 'active'".
            '  ) '.
            'RETURNING item.*',
            [$itemId, $firmId]
        );

        if ($row === null) {
            return null;
        }

        $requeued = IntegrationSyncItem::hydrate([(array) $row])->first();

        $this->requeueAudit->record(
            IntegrationRequeueAuditLogger::EVENT_SYNC_ITEM_REQUEUED,
            table: 'integration_sync_items',
            firmId: $firmId,
            id: $itemId,
            reasonCode: $reasonCode,
            actorFirmUserId: $actorFirmUserId,
            requeueCount: $requeued->requeue_count,
        );

        return $requeued;
    }
}
