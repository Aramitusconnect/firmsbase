<?php

namespace App\Services;

use App\Enums\AccountingExportLineStatus;
use App\Models\AccountingExportBatch;

/**
 * AccountingExportSimulationService — local-only accounting export
 * simulation. It updates local batch and line status records and writes
 * local line errors. It does not contact any external provider.
 */
class AccountingExportSimulationService
{
    public function __construct(
        private readonly AccountingExportBatchService $batchService,
        private readonly AccountingExportErrorLogger $errorLogger,
    ) {
    }

    public function run(AccountingExportBatch $batch): AccountingExportBatch
    {
        $batch = $this->batchService->markInProgress($batch);

        $anyFailed = false;

        foreach ($batch->lines()->where('status', AccountingExportLineStatus::Pending->value)->get() as $line) {
            if ($line->chart_of_accounts_id === null) {
                $line->update(['status' => AccountingExportLineStatus::Failed]);
                $this->errorLogger->log($line, 'chart_of_accounts_id', 'No chart of accounts mapping was found for this record.');
                $anyFailed = true;

                continue;
            }

            $line->update(['status' => AccountingExportLineStatus::Exported]);
        }

        return $anyFailed
            ? $this->batchService->markCompletedWithErrors($batch)
            : $this->batchService->markCompleted($batch);
    }
}
