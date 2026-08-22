<?php

namespace App\Services;

use App\Enums\AccountingExportLineStatus;
use App\Models\AccountingExportBatch;

/**
 * AccountingExportSimulationService — local-only accounting export
 * simulation. It updates local batch and line status records and writes
 * local line errors. It does not contact any external provider.
 *
 * accounting_export_lines now has permanent FORCE ROW LEVEL SECURITY
 * (see database/migrations/2026_08_27_950024_prepare_row_level_security_
 * and_force_rls_on_accounting_export_lines_table.php). run() does NOT
 * get one wrap spanning the whole loop — that would newly make the
 * whole simulation run atomic, a real, unapproved behavior change that
 * does not exist today. Instead: the ->get() read of pending lines
 * gets its own independent runWithFirmContext() wrap; each per-line
 * $line->update([...]) call gets its OWN independent wrap, called once
 * per loop iteration exactly as today (not one wrap for the whole
 * loop). $this->batchService->markInProgress()/markCompleted()/
 * markCompletedWithErrors() keep their own existing independent wraps
 * (AccountingExportBatchService), called exactly as today.
 * $this->errorLogger->log() writes to the sibling, out-of-scope
 * accounting_export_errors table — verified directly against
 * AccountingExportErrorLogger::log(): it does perform its own DB write
 * (AccountingExportError::create()), so it gets its own independent
 * wrap too, matching the same per-call-independent-wrap shape rather
 * than sharing the preceding $line->update() call's wrap.
 */
class AccountingExportSimulationService
{
    public function __construct(
        private readonly AccountingExportBatchService $batchService,
        private readonly AccountingExportErrorLogger $errorLogger,
    ) {}

    public function run(AccountingExportBatch $batch): AccountingExportBatch
    {
        $batch = $this->batchService->markInProgress($batch);

        $anyFailed = false;

        $pendingLines = (new TenantContextService)->runWithFirmContext(
            $batch->firm_id,
            fn () => $batch->lines()->where('status', AccountingExportLineStatus::Pending->value)->get(),
        );

        foreach ($pendingLines as $line) {
            if ($line->chart_of_accounts_id === null) {
                (new TenantContextService)->runWithFirmContext($batch->firm_id, fn () => $line->update(['status' => AccountingExportLineStatus::Failed]));
                (new TenantContextService)->runWithFirmContext($batch->firm_id, fn () => $this->errorLogger->log($line, 'chart_of_accounts_id', 'No chart of accounts mapping was found for this record.'));
                $anyFailed = true;

                continue;
            }

            (new TenantContextService)->runWithFirmContext($batch->firm_id, fn () => $line->update(['status' => AccountingExportLineStatus::Exported]));
        }

        return $anyFailed
            ? $this->batchService->markCompletedWithErrors($batch)
            : $this->batchService->markCompleted($batch);
    }
}
