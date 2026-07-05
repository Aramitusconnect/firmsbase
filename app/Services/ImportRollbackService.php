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
 */
class ImportRollbackService
{
    public function __construct(
        private readonly ImportAuditService $auditService,
    ) {
    }

    public function rollbackBatch(ImportBatch $batch): ImportBatch
    {
        foreach ($batch->rollbackRecords()->where('status', RollbackRecordStatus::Pending->value)->get() as $record) {
            $this->rollbackRecord($record);
        }

        $batch->rows()->where('status', ImportRowStatus::Applied->value)->update(['status' => ImportRowStatus::RolledBack->value]);

        $batch->update(['status' => ImportBatchStatus::RolledBack, 'rolled_back_at' => now()]);

        $this->auditService->record($batch, ImportAuditEventType::RollbackCompleted);

        return $batch->fresh();
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
