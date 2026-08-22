<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Data\SanitizedSyncFailureSummary;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Enums\SyncRunType;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Exceptions\SyncRunAlreadyInProgressException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncCursor;
use App\Integrations\Models\IntegrationSyncRun;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\TimelineEventRecorder;
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

    public function __construct(private readonly TimelineEventRecorder $events) {}

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
        ?int $actorFirmUserId = null,
    ): IntegrationSyncRun {
        $runType = $this->determineRunType($direction, $triggerSource, $cursor, $retriedRunId);

        try {
            // Nested DB::transaction() -> PostgreSQL SAVEPOINT (see this
            // method's "POST-DIFF-REVIEW FIX" docblock note): confines a
            // caught UniqueConstraintViolationException's transaction-abort
            // to this savepoint only, so the catch block's re-SELECT below
            // still runs against a healthy outer transaction.
            $run = DB::transaction(function () use ($connection, $resourceType, $direction, $runType, $triggerSource, $retriedRunId, $triggeringWebhookEventId) {
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

            // Checkpoint 9 addition (frozen design §3): fires ONLY on a
            // genuinely successful insert (this line), never on the
            // catch branch below, which re-attaches to an ALREADY-
            // existing run rather than starting a new one.
            $this->events->record($connection->firm, 'integration_sync.run_started', $run, $this->resolveOptionalActorUser($actorFirmUserId), [
                'sync_run_id' => $run->id,
                'firm_integration_id' => $connection->id,
                'resource_type' => $resourceType,
                'sync_direction' => $direction->value,
                'run_type' => $runType->value,
                'trigger_source' => $triggerSource->value,
            ]);

            return $run;
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
     *
     * Checkpoint 9 additions (frozen design §3): fires
     * `integration_sync.run_completed` (Succeeded/PartialFailure),
     * `integration_sync.run_failed` (Failed), or
     * `integration_sync.run_cancelled` (Cancelled) on the terminal
     * transitions this method performs — never on a non-terminal
     * transition (e.g. into Running). $actorFirmUserId and
     * $failureSummary are both new, OPTIONAL, trailing parameters —
     * existing callers outside this checkpoint's frozen file allowlist
     * (`app/Jobs/PullSyncJob.php`, `app/Jobs/PushSyncJob.php`) keep
     * passing only `$errorSummary` as a plain string, unaffected.
     * $failureSummary (a SanitizedSyncFailureSummary,
     * agent-9h-architecture-security-review.md §2.3) is consumed for
     * both `error_summary` (when the caller does not already supply a
     * raw string) and the `run_failed` event's own metadata — never a
     * blanket free-text string built ad hoc.
     */
    public function transitionStatus(
        IntegrationSyncRun $run,
        SyncRunStatus $newStatus,
        ?string $errorSummary = null,
        ?int $actorFirmUserId = null,
        ?SanitizedSyncFailureSummary $failureSummary = null,
    ): IntegrationSyncRun {
        $allowed = self::VALID_TRANSITIONS[$run->status->value] ?? [];

        if (! in_array($newStatus, $allowed, true)) {
            throw new RuntimeException(
                "Cannot transition sync run {$run->id} from {$run->status->value} to {$newStatus->value}."
            );
        }

        $errorSummary ??= $failureSummary?->toSummaryText();

        $attributes = ['status' => $newStatus, 'error_summary' => $errorSummary ?? $run->error_summary];

        if ($newStatus === SyncRunStatus::Running && $run->started_at === null) {
            $attributes['started_at'] = now();
        }

        if (in_array($newStatus, self::TERMINAL_STATUSES, true)) {
            $attributes['finished_at'] = now();
        }

        $run->update($attributes);

        $fresh = $run->fresh();

        $this->recordTerminalTransitionEvent($fresh, $newStatus, $actorFirmUserId, $failureSummary);

        return $fresh;
    }

    /**
     * Checkpoint 9 addition (frozen design §3). $actorFirmUserId is
     * null for every non-manual transition (the ordinary case — most
     * terminal transitions are driven by a batch loop/job, not a live
     * user action).
     */
    private function recordTerminalTransitionEvent(
        IntegrationSyncRun $run,
        SyncRunStatus $newStatus,
        ?int $actorFirmUserId,
        ?SanitizedSyncFailureSummary $failureSummary,
    ): void {
        $actor = $this->resolveOptionalActorUser($actorFirmUserId);

        $baseMetadata = [
            'sync_run_id' => $run->id,
            'firm_integration_id' => $run->firm_integration_id,
            'items_total' => $run->items_total,
            'items_succeeded' => $run->items_succeeded,
            'items_failed' => $run->items_failed,
            'items_skipped' => $run->items_skipped,
        ];

        if (in_array($newStatus, [SyncRunStatus::Succeeded, SyncRunStatus::PartialFailure], true)) {
            $this->events->record($run->firm, 'integration_sync.run_completed', $run, $actor, $baseMetadata);

            return;
        }

        if ($newStatus === SyncRunStatus::Failed) {
            $this->events->record($run->firm, 'integration_sync.run_failed', $run, $actor, array_merge($baseMetadata, [
                'error_summary' => $failureSummary?->toSummaryText() ?? $run->error_summary,
            ]));

            return;
        }

        if ($newStatus === SyncRunStatus::Cancelled) {
            $this->events->record($run->firm, 'integration_sync.run_cancelled', $run, $actor, $baseMetadata);
        }
    }

    /**
     * Cooperative cancellation signal (agent-6e §9.1) — sets
     * cancel_requested_at only; the owning batch loop checks this
     * between batches and stops, never mid-transaction. No Cancelling
     * status is added (principle 2 — a nullable timestamp is
     * sufficient).
     *
     * Checkpoint 9 addition: accepts an optional $actorFirmUserId for
     * signature symmetry with startRun()'s new trailing parameter — a
     * FirmUser-initiated cancellation request and the run's EVENTUAL
     * terminal transition to Cancelled (which is what actually fires
     * `integration_sync.run_cancelled`, per frozen design §3's "fires
     * on: terminal transition to Cancelled") may happen in different
     * processes/jobs entirely (this method only sets a cooperative
     * flag; the owning batch loop performs the actual
     * transitionStatus(Cancelled, ...) call later, outside this
     * checkpoint's frozen file allowlist). No column exists on
     * `integration_sync_runs` to durably carry "who requested this"
     * across that gap, so this parameter is NOT yet persisted or
     * forwarded automatically — a future caller that holds the same
     * actor at both request- and completion-time should pass it
     * directly to the later transitionStatus(..., $actorFirmUserId)
     * call itself.
     */
    public function requestCancellation(IntegrationSyncRun $run, ?int $actorFirmUserId = null): IntegrationSyncRun
    {
        if ($run->isTerminal()) {
            throw new RuntimeException("Cannot request cancellation of already-terminal sync run {$run->id}.");
        }

        $run->update(['cancel_requested_at' => now()]);

        return $run->fresh();
    }

    /**
     * Resolves an optional actor for audit-event recording only — never
     * throws on an unresolvable id (the actor is evidence, not an
     * authorization check; authorization already happened, if at all,
     * before this service was ever called).
     */
    private function resolveOptionalActorUser(?int $actorFirmUserId): ?User
    {
        if ($actorFirmUserId === null) {
            return null;
        }

        return FirmUser::query()->find($actorFirmUserId)?->user;
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
