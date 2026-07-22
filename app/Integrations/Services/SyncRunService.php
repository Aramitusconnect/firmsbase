<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Enums\SyncRunType;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Exceptions\SyncRunAlreadyInProgressException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncCursor;
use App\Integrations\Models\IntegrationSyncRun;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SyncRunService — the ONLY writer of `integration_sync_runs`
 * (Checkpoint 6, reviews/checkpoint-06/frozen-design-post-review.md
 * §2/§4/§8; agent-6e-sync-run-item-cursor-semantics.md §5.3/§9).
 * Mirrors ProviderConnectionService::transitionStatus()'s sole-writer
 * precedent — `status`/`run_type` are never mutated directly on the
 * model by any other caller.
 */
final class SyncRunService
{
    private const TERMINAL_STATUSES = [
        SyncRunStatus::Succeeded,
        SyncRunStatus::PartialFailure,
        SyncRunStatus::Failed,
        SyncRunStatus::Cancelled,
    ];

    /**
     * @var array<int, array{0: SyncRunStatus, 1: array<int, SyncRunStatus>}>
     */
    private const VALID_TRANSITIONS = [
        // From Pending
        SyncRunStatus::Pending->value => [SyncRunStatus::Running, SyncRunStatus::Cancelled, SyncRunStatus::Failed],
        // From Running
        SyncRunStatus::Running->value => [
            SyncRunStatus::Succeeded, SyncRunStatus::PartialFailure, SyncRunStatus::Failed, SyncRunStatus::Cancelled,
        ],
    ];

    /**
     * Starts a new run for a (connection, resource_type, direction)
     * scope. Layer 1 concurrency defense (partial unique index
     * `integration_sync_runs_one_active_per_scope`) is enforced by
     * attempting the plain insert and catching
     * UniqueConstraintViolationException — never a pre-check-then-insert
     * (agent-6c §6). run_type is computed via a fixed precedence order
     * (determineRunType()), never caller-suppliable directly, so
     * storage is always unambiguous.
     *
     * POST-DIFF-REVIEW FIX (checkpoint-06 verification pass) — the
     * insert attempt below is wrapped in its own DB::transaction() so
     * PostgreSQL issues a SAVEPOINT: startRun() is always called from
     * inside TenantContextService::runWithFirmContext()'s own outer
     * DB::transaction(), and PostgreSQL aborts the ENTIRE current
     * transaction block on any error until it is rolled back — without
     * a nested transaction here, a caught UniqueConstraintViolationException
     * would poison the outer transaction, and the catch block's own
     * re-SELECT for the already-in-progress run would then itself fail
     * against the already-aborted transaction instead of finding it.
     * The nested DB::transaction() call scopes PostgreSQL's abort to
     * the SAVEPOINT only, so the re-SELECT below runs against a still-
     * healthy outer transaction.
     */
    public function startRun(
        FirmIntegration $connection,
        string $resourceType,
        SyncDirection $direction,
        SyncTriggerSource $triggerSource,
        ?IntegrationSyncCursor $cursor = null,
        ?int $retriedRunId = null,
        ?int $triggeringWebhookEventId = null,
    ): IntegrationSyncRun {
        $runType = $this->determineRunType($direction, $triggerSource, $cursor, $retriedRunId);

        try {
            // Nested DB::transaction() -> PostgreSQL SAVEPOINT (see this
            // method's "POST-DIFF-REVIEW FIX" docblock note): confines a
            // caught UniqueConstraintViolationException's transaction-abort
            // to this savepoint only, so the catch block's re-SELECT below
            // still runs against a healthy outer transaction.
            return DB::transaction(function () use ($connection, $resourceType, $direction, $runType, $triggerSource, $retriedRunId, $triggeringWebhookEventId) {
                // Checkpoint 7 addition (frozen design §11): optional,
                // additive, backward-compatible
                // `triggering_webhook_event_id`, included in this SAME
                // single create() call — never a second UPDATE after
                // the fact. Uses the query builder's forceCreate()
                // (mass-assignment-unguarded) rather than plain
                // create(): App\Integrations\Models\IntegrationSyncRun
                // is outside this checkpoint's frozen file allowlist,
                // so `triggering_webhook_event_id` cannot be added to
                // its $fillable array — forceCreate() lets this one new
                // column be set without touching that model file. See
                // the 2026_09_06_060005_add_triggering_webhook_event_id_to_integration_sync_runs_table
                // migration's own docblock for the full reasoning.
                return IntegrationSyncRun::query()->forceCreate([
                    'firm_id' => $connection->firm_id,
                    'firm_integration_id' => $connection->id,
                    'resource_type' => $resourceType,
                    'sync_direction' => $direction,
                    'run_type' => $runType,
                    'trigger_source' => $triggerSource,
                    'status' => SyncRunStatus::Pending,
                    'retried_run_id' => $retriedRunId,
                    'triggering_webhook_event_id' => $triggeringWebhookEventId,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = IntegrationSyncRun::query()
                ->where('firm_integration_id', $connection->id)
                ->where('resource_type', $resourceType)
                ->where('sync_direction', $direction)
                ->whereIn('status', [SyncRunStatus::Pending, SyncRunStatus::Running])
                ->first();

            throw new SyncRunAlreadyInProgressException($existing ?? throw $e);
        }
    }

    /**
     * Fixed, top-to-bottom precedence order (agent-6e §5.3): Retry >
     * Repair > Manual > Scheduled > Outbound > Incremental > Initial —
     * so every run has exactly one unambiguous type even though it may
     * loosely satisfy more than one description.
     */
    public function determineRunType(
        SyncDirection $direction,
        SyncTriggerSource $triggerSource,
        ?IntegrationSyncCursor $cursor,
        ?int $retriedRunId,
    ): SyncRunType {
        if ($retriedRunId !== null) {
            return SyncRunType::Retry;
        }

        if ($cursor !== null && $cursor->status->value === 'cursor_invalid') {
            return SyncRunType::Repair;
        }

        if ($triggerSource === SyncTriggerSource::Manual) {
            return SyncRunType::Manual;
        }

        if ($triggerSource === SyncTriggerSource::SchedulerPoller) {
            return SyncRunType::Scheduled;
        }

        if ($direction === SyncDirection::Outbound) {
            return SyncRunType::Outbound;
        }

        if ($cursor !== null && $cursor->cursor_value !== null) {
            return SyncRunType::Incremental;
        }

        return SyncRunType::Initial;
    }

    /**
     * Sole-writer state-transition primitive, mirroring
     * ProviderConnectionService::transitionStatus() — validates the
     * prior status before writing (this codebase has zero CREATE
     * TRIGGER precedent, so transition legality is a service
     * responsibility, not a DB-enforced one, per frozen-design-post-
     * review.md §8). Terminal statuses (Succeeded/PartialFailure/
     * Failed/Cancelled) never transition further; a new
     * IntegrationSyncRun row is always created for further work.
     */
    public function transitionStatus(IntegrationSyncRun $run, SyncRunStatus $newStatus, ?string $errorSummary = null): IntegrationSyncRun
    {
        $allowed = self::VALID_TRANSITIONS[$run->status->value] ?? [];

        if (! in_array($newStatus, $allowed, true)) {
            throw new RuntimeException(
                "Cannot transition sync run {$run->id} from {$run->status->value} to {$newStatus->value}."
            );
        }

        $attributes = ['status' => $newStatus, 'error_summary' => $errorSummary ?? $run->error_summary];

        if ($newStatus === SyncRunStatus::Running && $run->started_at === null) {
            $attributes['started_at'] = now();
        }

        if (in_array($newStatus, self::TERMINAL_STATUSES, true)) {
            $attributes['finished_at'] = now();
        }

        $run->update($attributes);

        return $run->fresh();
    }

    /**
     * Cooperative cancellation signal (agent-6e §9.1) — sets
     * cancel_requested_at only; the owning batch loop checks this
     * between batches and stops, never mid-transaction. No Cancelling
     * status is added (principle 2 — a nullable timestamp is
     * sufficient).
     */
    public function requestCancellation(IntegrationSyncRun $run): IntegrationSyncRun
    {
        if ($run->isTerminal()) {
            throw new RuntimeException("Cannot request cancellation of already-terminal sync run {$run->id}.");
        }

        $run->update(['cancel_requested_at' => now()]);

        return $run->fresh();
    }

    /**
     * Run-finalization item-count reconciliation (agent-6e §17): given
     * final counts, returns the correct terminal SyncRunStatus. Pure
     * calculation, no I/O — the caller still calls transitionStatus()
     * with the returned value.
     */
    public function determineTerminalStatus(int $itemsTotal, int $itemsSucceeded, int $itemsFailed): SyncRunStatus
    {
        if ($itemsFailed === 0) {
            return SyncRunStatus::Succeeded;
        }

        if ($itemsSucceeded > 0 && $itemsFailed > 0) {
            return SyncRunStatus::PartialFailure;
        }

        return SyncRunStatus::Failed;
    }
}
