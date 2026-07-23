<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FirmActivationStatus;
use App\Integrations\Services\RetentionSweepAuditLogger;
use App\Jobs\RetentionSweepJob;
use App\Models\Firm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * integrations:retention:sweep — Layer 1 of the retention sweep's
 * two-layer dispatch loop (Checkpoint 8,
 * agent-8g-retention-cleanup-design.md §7.3;
 * agent-8h-architecture-security-review.md §1 items 7-9). A plain,
 * non-tenant, non-ShouldQueue Artisan command. Runs the ONE
 * platform-owned sweeper (integration_webhook_receipts — no firm_id
 * column at all, no RLS backstop) DIRECTLY, synchronously, here (not
 * queued — a single bounded operation) — then enumerates active firm
 * ids and dispatches one RetentionSweepJob per firm id, exactly as
 * App\Console\Commands\DispatchOutboxEventsCommand dispatches one
 * OutboxDispatchJob per firm id. Scheduled `daily()->withoutOverlapping()`
 * in bootstrap/app.php — a coarser cadence than the outbox dispatcher
 * is justified by the data itself: every retention window in this
 * checkpoint is measured in days (shortest: 7), so a missed/delayed
 * daily tick costs at most fractional-day drift against a multi-day-
 * to-multi-year budget.
 */
final class SweepIntegrationRetentionCommand extends Command
{
    protected $signature = 'integrations:retention:sweep {--dry-run} {--batch-size=500}';

    protected $description = 'Sweeps the platform-owned webhook-receipts table directly, then dispatches one RetentionSweepJob per activated firm.';

    public function handle(RetentionSweepAuditLogger $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');

        $audit->record(RetentionSweepAuditLogger::EVENT_RUN_STARTED, dryRun: $dryRun);

        $this->sweepWebhookReceipts($dryRun, $batchSize, $audit);

        Firm::query()
            ->where('activation_status', FirmActivationStatus::Activated)
            ->pluck('id')
            ->each(fn (int $firmId) => RetentionSweepJob::dispatch($firmId, $dryRun, $batchSize));

        $audit->record(RetentionSweepAuditLogger::EVENT_RUN_COMPLETED, dryRun: $dryRun);

        return self::SUCCESS;
    }

    /**
     * integration_webhook_receipts (agent-8g §5.6/§5.7/§6) — the ONE
     * retention target this checkpoint deletes from with no RLS
     * backstop at all. Extra safeguards, strictly more than any
     * tenant-table sweeper:
     *  1. No table-name interpolation — a single hardcoded literal.
     *  2. A mandatory pre-delete SELECT COUNT(*) using the IDENTICAL
     *     WHERE clause, logged BEFORE any row is removed — so an
     *     anomalous spike (e.g. a misconfigured retention_days=0
     *     wiping the whole table) is visible in the audit log before
     *     the fact, not discovered after.
     *  3. A separate, smaller max-batches ceiling
     *     (config('integrations.retention.platform_max_batches_per_run'))
     *     than tenant tables use for THEIR own batch loop.
     *  4. Recomputes eligibility directly from verification_outcome +
     *     received_at — NEVER trusts the stored retention_deadline
     *     column (App\Integrations\Services\InboundWebhookReceiptService's
     *     Checkpoint 8 fix corrects that column's computation, but this
     *     sweep independently enforces the frozen windows regardless,
     *     matching this codebase's "never trust a single layer for a
     *     destructive operation" discipline).
     */
    private function sweepWebhookReceipts(bool $dryRun, int $batchSize, RetentionSweepAuditLogger $audit): void
    {
        $invalidRetentionDays = (int) config('integrations.webhook.receipt_retention_days', 7);
        $verifiedRetentionDays = (int) config('integrations.webhook.receipt_verified_retention_days', 30);
        $maxBatches = (int) config('integrations.retention.platform_max_batches_per_run', 50);

        $where = "(verification_outcome <> 'verified' AND received_at <= statement_timestamp() - (? || ' days')::interval) ".
            "OR (verification_outcome = 'verified' AND received_at <= statement_timestamp() - (? || ' days')::interval)";

        $bindings = [$invalidRetentionDays, $verifiedRetentionDays];

        $preDeleteCount = (int) (DB::selectOne(
            "SELECT COUNT(*) AS c FROM integration_webhook_receipts WHERE {$where}",
            $bindings,
        )->c ?? 0);

        $audit->record(
            RetentionSweepAuditLogger::EVENT_TABLE_BATCH_COMPLETED,
            table: 'integration_webhook_receipts',
            count: $preDeleteCount,
            dryRun: true,
        );

        if ($dryRun) {
            $audit->record(
                RetentionSweepAuditLogger::EVENT_TABLE_SWEPT,
                table: 'integration_webhook_receipts',
                count: $preDeleteCount,
                dryRun: true,
            );

            return;
        }

        $sql = 'WITH candidate AS ('.
            "  SELECT id FROM integration_webhook_receipts WHERE {$where} ".
            '  ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED'.
            ') '.
            'DELETE FROM integration_webhook_receipts WHERE id IN (SELECT id FROM candidate) RETURNING id';

        $totalDeleted = 0;
        $batchesRun = 0;

        do {
            $rows = DB::select($sql, [...$bindings, $batchSize]);
            $batchCount = count($rows);
            $totalDeleted += $batchCount;
            $batchesRun++;
        } while ($batchCount === $batchSize && $batchesRun < $maxBatches);

        $audit->record(
            RetentionSweepAuditLogger::EVENT_TABLE_SWEPT,
            table: 'integration_webhook_receipts',
            count: $totalDeleted,
            dryRun: false,
        );
    }
}
