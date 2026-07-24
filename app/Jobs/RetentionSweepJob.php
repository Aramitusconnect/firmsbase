<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Services\RetentionSweepAuditLogger;
use App\Support\TenantAwareJobContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RetentionSweepJob — Layer 2 of the retention sweep's two-layer
 * dispatch loop (Checkpoint 8, agent-8g-retention-cleanup-design.md
 * §5/§7; agent-8h-architecture-security-review.md §1 items 7-9). For
 * the given firm, sweeps every tenant-owned table's own eligible rows
 * in a fixed order (sync items before sync runs — a best-effort
 * optimization that makes §5.3's NOT EXISTS cascade-hazard guard
 * converge sooner; the guard itself, not the ordering, is the actual
 * correctness mechanism), then outbox events, OAuth states, resolved
 * conflicts, and finally the processed-webhook-events redact-then-
 * delete mechanism.
 *
 * CRITICAL correctness detail (agent-8g §7.1): every batch calls
 * TenantContextService::runWithFirmContext() ONCE PER BATCH, never once
 * per firm — that method wraps its ENTIRE callback in one
 * DB::transaction(); wrapping every batch for a firm in a single
 * runWithFirmContext() call would put every batch inside ONE giant
 * transaction, violating the "chunked deletes, not one giant
 * transaction" requirement and losing crash-resumability (a crash
 * mid-firm would roll back every already-"deleted" batch, not just the
 * in-flight one). Each batch iteration below opens its own fresh
 * transaction, establishes tenant context, runs exactly one bounded
 * DELETE/UPDATE, and commits independently of every other batch.
 *
 * Every eligibility predicate uses statement_timestamp(), never now()
 * — matching IntegrationOutboxEventService::claim()'s own established
 * discipline — and every DELETE/UPDATE uses the proven
 * `WITH candidate AS (...FOR UPDATE SKIP LOCKED) DELETE/UPDATE ...
 * RETURNING id` CTE shape, never the naive non-CTE form.
 */
final class RetentionSweepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public function __construct(
        public readonly int $firmId,
        public readonly bool $dryRun = false,
        public readonly int $batchSize = 500,
    ) {
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('retention:'.$this->firmId))->releaseAfter(60)->expireAfter(1800),
        ];
    }

    public function handle(RetentionSweepAuditLogger $audit): void
    {
        $audit->record(RetentionSweepAuditLogger::EVENT_RUN_STARTED, firmId: $this->firmId, dryRun: $this->dryRun);

        $this->sweepSyncItems($audit);
        $this->sweepSyncRuns($audit);
        $this->sweepOutboxEvents($audit);
        $this->sweepOauthStates($audit);
        $this->sweepResolvedConflicts($audit);
        $this->sweepProcessedWebhookEvents($audit);

        $audit->record(RetentionSweepAuditLogger::EVENT_RUN_COMPLETED, firmId: $this->firmId, dryRun: $this->dryRun);
    }

    /**
     * Sync items (agent-8g §5.4): 60 days from terminal_at, terminal
     * statuses only. Deleting an item a conflict row references via
     * sync_item_id is already safe by that FK's own ON DELETE SET NULL
     * design (Checkpoint 6) — no additional guard needed here.
     */
    private function sweepSyncItems(RetentionSweepAuditLogger $audit): void
    {
        if ($this->firmDataSweepDisabled('integration_sync_items')) {
            return;
        }

        $retentionDays = (int) config('integrations.sync_items.retention_days', 60);

        $sql = $this->dryRun
            ? 'SELECT id FROM integration_sync_items '.
              "WHERE firm_id = ? AND status IN ('succeeded','failed_permanent','skipped') ".
              'AND terminal_at IS NOT NULL '.
              "AND terminal_at <= statement_timestamp() - (? || ' days')::interval ".
              'ORDER BY id LIMIT ?'
            : 'WITH candidate AS ('.
              '  SELECT id FROM integration_sync_items '.
              "  WHERE firm_id = ? AND status IN ('succeeded','failed_permanent','skipped') ".
              '  AND terminal_at IS NOT NULL '.
              "  AND terminal_at <= statement_timestamp() - (? || ' days')::interval ".
              '  ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED'.
              ') '.
              'DELETE FROM integration_sync_items WHERE id IN (SELECT id FROM candidate) RETURNING id';

        $this->sweepInBatches('integration_sync_items', $sql, [$this->firmId, $retentionDays], $audit);
    }

    /**
     * Sync runs (agent-8g §5.3): 180 days from finished_at, terminal
     * statuses only, GUARDED by a hard NOT EXISTS predicate against any
     * remaining child integration_sync_items row — required because
     * that child table composite-FKs to this one with
     * cascadeOnDelete(); without this guard a sync run past its own
     * 180-day window but with a child item that hasn't yet cleared its
     * OWN, independent, shorter 60-day window would have that child
     * destroyed early via cascade. Never a bug: the run simply stays
     * ineligible for as long as it has any remaining child, no matter
     * that child's age/status — self-correcting once the item sweep
     * (run BEFORE this one above) clears the remaining children.
     */
    private function sweepSyncRuns(RetentionSweepAuditLogger $audit): void
    {
        if ($this->firmDataSweepDisabled('integration_sync_runs')) {
            return;
        }

        $retentionDays = (int) config('integrations.sync_runs.retention_days', 180);

        $sql = $this->dryRun
            ? 'SELECT sr.id FROM integration_sync_runs sr '.
              "WHERE sr.firm_id = ? AND sr.status IN ('succeeded','partial_failure','failed','cancelled') ".
              "AND sr.finished_at <= statement_timestamp() - (? || ' days')::interval ".
              'AND NOT EXISTS (SELECT 1 FROM integration_sync_items si WHERE si.sync_run_id = sr.id) '.
              'ORDER BY sr.id LIMIT ?'
            : 'WITH candidate AS ('.
              '  SELECT sr.id FROM integration_sync_runs sr '.
              "  WHERE sr.firm_id = ? AND sr.status IN ('succeeded','partial_failure','failed','cancelled') ".
              "  AND sr.finished_at <= statement_timestamp() - (? || ' days')::interval ".
              '  AND NOT EXISTS (SELECT 1 FROM integration_sync_items si WHERE si.sync_run_id = sr.id) '.
              '  ORDER BY sr.id LIMIT ? FOR UPDATE SKIP LOCKED'.
              ') '.
              'DELETE FROM integration_sync_runs WHERE id IN (SELECT id FROM candidate) RETURNING id';

        $this->sweepInBatches('integration_sync_runs', $sql, [$this->firmId, $retentionDays], $audit);
    }

    /**
     * Outbox events (agent-8g §5.2): three terminal statuses, three
     * independent, already-frozen (Checkpoint 6) retention windows.
     * Non-terminal (pending/processing) rows are structurally excluded
     * — a stuck/locked row can never match any branch regardless of how
     * stale its locked_at is.
     */
    private function sweepOutboxEvents(RetentionSweepAuditLogger $audit): void
    {
        $completedDays = (int) config('integrations.outbox.completed_retention_days', 30);
        $deadLetteredDays = (int) config('integrations.outbox.dead_lettered_retention_days', 90);
        $cancelledDays = (int) config('integrations.outbox.cancelled_retention_days', 30);

        $whereClause = 'firm_id = ? AND ('.
            "(status = 'completed' AND completed_at <= statement_timestamp() - (? || ' days')::interval) ".
            "OR (status = 'dead_lettered' AND dead_lettered_at <= statement_timestamp() - (? || ' days')::interval) ".
            "OR (status = 'cancelled' AND cancelled_at <= statement_timestamp() - (? || ' days')::interval)".
            ')';

        $sql = $this->dryRun
            ? "SELECT id FROM integration_outbox_events WHERE {$whereClause} ORDER BY id LIMIT ?"
            : 'WITH candidate AS ('.
              "  SELECT id FROM integration_outbox_events WHERE {$whereClause} ".
              '  ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED'.
              ') '.
              'DELETE FROM integration_outbox_events WHERE id IN (SELECT id FROM candidate) RETURNING id';

        $this->sweepInBatches(
            'integration_outbox_events',
            $sql,
            [$this->firmId, $completedDays, $deadLetteredDays, $cancelledDays],
            $audit,
        );
    }

    /**
     * OAuth states (agent-8g §5.1): two independent eligibility
     * classes. Class A (consumed) always runs. Class B (unconsumed but
     * expired) runs ONLY when
     * config('integrations.oauth_states.unconsumed_expired_retention_hours')
     * has been explicitly set — NO inline fallback default exists
     * anywhere for it (a materially NEW retention rule with no prior
     * frozen anchor, unlike Class A's point-pick inside an
     * already-approved 24-72h range). Unconfigured means "never
     * delete," never "delete using an unsafe guessed default" — the
     * sweeper explicitly no-ops Class B and logs exactly once per run.
     */
    private function sweepOauthStates(RetentionSweepAuditLogger $audit): void
    {
        $consumedHours = (int) config('integrations.oauth_states.consumed_retention_hours', 72);

        $consumedWhere = 'firm_id = ? AND consumed_at IS NOT NULL '.
            "AND consumed_at <= statement_timestamp() - (? || ' hours')::interval";

        $consumedSql = $this->dryRun
            ? "SELECT id FROM integration_oauth_states WHERE {$consumedWhere} ORDER BY id LIMIT ?"
            : 'WITH candidate AS ('.
              "  SELECT id FROM integration_oauth_states WHERE {$consumedWhere} ".
              '  ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED'.
              ') '.
              'DELETE FROM integration_oauth_states WHERE id IN (SELECT id FROM candidate) RETURNING id';

        $this->sweepInBatches('integration_oauth_states', $consumedSql, [$this->firmId, $consumedHours], $audit);

        $unconsumedExpiredHours = config('integrations.oauth_states.unconsumed_expired_retention_hours');

        if ($unconsumedExpiredHours === null) {
            $audit->record(
                RetentionSweepAuditLogger::EVENT_OAUTH_STATE_UNCONSUMED_CLEANUP_NOT_CONFIGURED,
                table: 'integration_oauth_states',
                firmId: $this->firmId,
                dryRun: $this->dryRun,
            );

            return;
        }

        $unconsumedWhere = 'firm_id = ? AND consumed_at IS NULL '.
            "AND expires_at <= statement_timestamp() - (? || ' hours')::interval";

        $unconsumedSql = $this->dryRun
            ? "SELECT id FROM integration_oauth_states WHERE {$unconsumedWhere} ORDER BY id LIMIT ?"
            : 'WITH candidate AS ('.
              "  SELECT id FROM integration_oauth_states WHERE {$unconsumedWhere} ".
              '  ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED'.
              ') '.
              'DELETE FROM integration_oauth_states WHERE id IN (SELECT id FROM candidate) RETURNING id';

        $this->sweepInBatches(
            'integration_oauth_states',
            $unconsumedSql,
            [$this->firmId, (int) $unconsumedExpiredHours],
            $audit,
        );
    }

    /**
     * Resolved conflicts (agent-8g §5.5): 365 days from resolved_at.
     * Two independent, non-redundant safeguards: status NOT IN the two
     * open states, AND resolved_at IS NOT NULL — either alone would be
     * sufficient today; both together mean a single bug in one layer
     * cannot by itself cause an unresolved conflict to be deleted.
     */
    private function sweepResolvedConflicts(RetentionSweepAuditLogger $audit): void
    {
        if ($this->firmDataSweepDisabled('integration_conflicts')) {
            return;
        }

        $retentionDays = (int) config('integrations.conflicts.retention_days', 365);

        $where = "firm_id = ? AND status NOT IN ('detected', 'awaiting_review') ".
            'AND resolved_at IS NOT NULL '.
            "AND resolved_at <= statement_timestamp() - (? || ' days')::interval";

        $sql = $this->dryRun
            ? "SELECT id FROM integration_conflicts WHERE {$where} ORDER BY id LIMIT ?"
            : 'WITH candidate AS ('.
              "  SELECT id FROM integration_conflicts WHERE {$where} ".
              '  ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED'.
              ') '.
              'DELETE FROM integration_conflicts WHERE id IN (SELECT id FROM candidate) RETURNING id';

        $this->sweepInBatches('integration_conflicts', $sql, [$this->firmId, $retentionDays], $audit);
    }

    /**
     * Processed webhook events (agent-8g §8) — the redact-then-delete
     * mechanism Checkpoint 7 explicitly deferred. Stage 1 redacts
     * provider-originated content at 400 days (retention_deadline,
     * already correctly encoded — see InboundWebhookEventService);
     * idempotency without a redacted_at column: the WHERE clause itself
     * detects "not yet redacted" by checking whether any to-be-redacted
     * column still holds content. Stage 2 deletes at 2555 days,
     * computed from received_at directly (never from
     * retention_deadline, which only ever encodes the 400-day redact
     * horizon). A non-terminal row past its redact deadline is NEVER
     * touched by either stage — counted and logged instead
     * (stuck-terminal-deadline visibility), never redacted/deleted out
     * from under a future processing attempt.
     */
    private function sweepProcessedWebhookEvents(RetentionSweepAuditLogger $audit): void
    {
        $redactWhere = 'firm_id = ? AND terminal_at IS NOT NULL '.
            'AND retention_deadline <= statement_timestamp() '.
            "AND (payload_reference_json <> '{}'::jsonb OR payload_hash IS NOT NULL ".
            'OR receipt_body_hash IS NOT NULL OR failure_detail IS NOT NULL)';

        $redactSql = $this->dryRun
            ? "SELECT id FROM integration_inbound_webhook_events WHERE {$redactWhere} ORDER BY id LIMIT ?"
            : 'WITH candidate AS ('.
              "  SELECT id FROM integration_inbound_webhook_events WHERE {$redactWhere} ".
              '  ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED'.
              ') '.
              'UPDATE integration_inbound_webhook_events '.
              "SET payload_reference_json = '{}'::jsonb, payload_hash = NULL, receipt_body_hash = NULL, failure_detail = NULL ".
              'WHERE id IN (SELECT id FROM candidate) RETURNING id';

        $this->sweepInBatches('integration_inbound_webhook_events', $redactSql, [$this->firmId], $audit);

        $deleteAfterDays = (int) config('integrations.webhook.event_delete_after_days', 2555);

        $deleteWhere = 'firm_id = ? AND terminal_at IS NOT NULL '.
            "AND received_at <= statement_timestamp() - (? || ' days')::interval";

        $deleteSql = $this->dryRun
            ? "SELECT id FROM integration_inbound_webhook_events WHERE {$deleteWhere} ORDER BY id LIMIT ?"
            : 'WITH candidate AS ('.
              "  SELECT id FROM integration_inbound_webhook_events WHERE {$deleteWhere} ".
              '  ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED'.
              ') '.
              'DELETE FROM integration_inbound_webhook_events WHERE id IN (SELECT id FROM candidate) RETURNING id';

        $this->sweepInBatches('integration_inbound_webhook_events', $deleteSql, [$this->firmId, $deleteAfterDays], $audit);

        // Stuck-terminal-deadline visibility (agent-8g §8.1): a row
        // whose retention_deadline has elapsed but which is still
        // non-terminal is never touched above — counted here purely for
        // operator visibility (an apparently-abandoned row is an
        // anomaly worth surfacing), never redacted/deleted.
        //
        // integration_inbound_webhook_events is FORCE-RLS'd with no
        // bypass, exactly like every other table this method reads —
        // this read-only count must run under this job's own firm's
        // tenant context via runInFirmContext(), mirroring
        // sweepInBatches()'s identical use of runInFirmContext() for
        // every other query in this method. An unscoped DB::table(...)
        // call here would silently always see zero rows.
        $stuckCount = (int) $this->runInFirmContext(
            $this->firmId,
            fn () => DB::table('integration_inbound_webhook_events')
                ->where('firm_id', $this->firmId)
                ->whereNull('terminal_at')
                ->where('retention_deadline', '<=', now())
                ->count()
        );

        if ($stuckCount > 0) {
            $audit->record(
                RetentionSweepAuditLogger::EVENT_STUCK_TERMINAL_DEADLINE_ROW,
                table: 'integration_inbound_webhook_events',
                firmId: $this->firmId,
                count: $stuckCount,
                dryRun: $this->dryRun,
            );
        }
    }

    /**
     * Checkpoint 13 P3 (finding #5, DISABLE_BY_DEFAULT —
     * agent-13h-testing-release-review.md §3/§4 item 2). Kill-switch
     * guard shared by the three FIRM-DATA, client/matter-adjacent sweeps
     * (sync items, sync runs, resolved conflicts). Returns true (and
     * logs a clear, greppable skip reason — never a silent no-op) when
     * the flag is off, which is the default: these sweeps delete firm
     * data that may be under a legal hold, and no legal-hold resolution
     * layer exists yet, so they stay gated until a human explicitly sets
     * `integrations.retention.sweep_firm_data_enabled` (or the
     * INTEGRATIONS_RETENTION_SWEEP_FIRM_DATA_ENABLED env). The
     * platform-owned webhook-receipts sweep (no client/matter linkage)
     * and the outbox/OAuth-state sweeps are deliberately NOT guarded by
     * this flag.
     */
    private function firmDataSweepDisabled(string $table): bool
    {
        if ((bool) config('integrations.retention.sweep_firm_data_enabled', false)) {
            return false;
        }

        Log::warning('integration_retention.firm_data_sweep_skipped_disabled', [
            'table' => $table,
            'firm_id' => $this->firmId,
            'dry_run' => $this->dryRun,
            'reason' => 'integrations.retention.sweep_firm_data_enabled is disabled (default off) — '
                .'firm-data retention sweeps are gated pending legal-hold resolution.',
        ]);

        return true;
    }

    /**
     * Bounded, batch-based, retryable sweep loop (agent-8g §7.1/§7.4):
     * each iteration opens its OWN runWithFirmContext() call (its own
     * fresh transaction), never one call wrapping every batch. Stops
     * when a batch returns fewer rows than $this->batchSize (no more
     * candidates) or the platform max-batches ceiling is reached.
     *
     * @param  array<int, mixed>  $baseBindings
     */
    private function sweepInBatches(string $table, string $sql, array $baseBindings, RetentionSweepAuditLogger $audit): void
    {
        $maxBatches = (int) config('integrations.retention.platform_max_batches_per_run', 50);
        $totalSwept = 0;
        $batchesRun = 0;

        do {
            $bindings = [...$baseBindings, $this->batchSize];

            $rows = $this->runInFirmContext($this->firmId, fn () => DB::select($sql, $bindings));

            $batchCount = count($rows);
            $totalSwept += $batchCount;
            $batchesRun++;

            if ($batchCount > 0) {
                $audit->record(
                    RetentionSweepAuditLogger::EVENT_TABLE_BATCH_COMPLETED,
                    table: $table,
                    firmId: $this->firmId,
                    count: $batchCount,
                    dryRun: $this->dryRun,
                );
            }
        } while ($batchCount === $this->batchSize && $batchesRun < $maxBatches);

        $audit->record(
            RetentionSweepAuditLogger::EVENT_TABLE_SWEPT,
            table: $table,
            firmId: $this->firmId,
            count: $totalSwept,
            dryRun: $this->dryRun,
        );
    }
}
