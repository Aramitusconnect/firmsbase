<?php

namespace App\Services;

use App\Enums\ImportAuditEventType;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Enums\RollbackRecordStatus;
use App\Models\ImportBatch;
use App\Models\ImportRollbackRecord;

/**
 * ImportRollbackService — the only writer that transitions
 * import_rollback_records to RolledBack, and deletes the production
 * record each one points at. Firm-scoped by construction: it never
 * looks up a rollback record outside the given ImportBatch's own
 * rollback-records relation.
 *
 * import_batches carries FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_08_29_970003_prepare_row_level_security_and_force_rls_on_import_batches_table.php).
 * rollbackBatch() wraps its whole body (rollback-records loop, the rows
 * bulk-update, the batch update, and the trailing fresh()) in a single
 * runWithFirmContext() call — previously this wrap only covered the
 * inner rollbackRecord() loop, leaving the rows bulk-update and batch
 * update outside any context. rollbackRecord() is only guaranteed
 * tenant-safe when called from inside rollbackBatch()'s wrap (or
 * another already-active same-firm context) — left public/unchanged
 * rather than narrowed to private/protected, since visibility is out
 * of this change's scope.
 */
class ImportRollbackService
{
    public function __construct(
        private readonly ImportAuditService $auditService,
    ) {
    }

    public function rollbackBatch(ImportBatch $batch): ImportBatch
    {
        return (new TenantContextService())->runWithFirmContext($batch->firm_id, function () use ($batch) {
            $records = $batch->rollbackRecords()->where('status', RollbackRecordStatus::Pending->value)->get();

            foreach ($records as $record) {
                $this->rollbackRecord($record);
            }

            $batch->rows()->where('status', ImportRowStatus::Applied->value)->update(['status' => ImportRowStatus::RolledBack->value]);

            $batch->update(['status' => ImportBatchStatus::RolledBack, 'rolled_back_at' => now()]);

            $this->auditService->record($batch, ImportAuditEventType::RollbackCompleted);

            return $batch->fresh();
        });
    }

    public function rollbackRecord(ImportRollbackRecord $record): ImportRollbackRecord
    {
        $modelClass = $record->rolled_back_record_type;
        $modelId = $record->rolled_back_record_id;

        if ($modelClass !== null && $modelId !== null && class_exists($modelClass)) {
            $modelClass::query()->whereKey($modelId)->delete();
        }

        $record->update([
            'status' => RollbackRecordStatus::RolledBack,
            'rolled_back_at' => now(),
        ]);

        return $record->fresh();
    }
}
