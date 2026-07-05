<?php

namespace App\Services;

use App\Enums\ImportAuditEventType;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Models\ImportBatch;
use App\ValueObjects\ImportPreviewResult;

/**
 * ImportPreviewService — dry run / preview only. NEVER creates a
 * production record for any entity type (project rule 2/11) — it only
 * runs validation + duplicate detection against already-staged rows
 * and summarizes the outcome.
 */
class ImportPreviewService
{
    public function __construct(
        private readonly ImportRowValidationService $validationService,
        private readonly ImportDuplicateDetectionService $duplicateDetectionService,
        private readonly ImportAuditService $auditService,
    ) {
    }

    public function preview(ImportBatch $batch): ImportPreviewResult
    {
        $this->validationService->validateBatch($batch);

        foreach ($batch->rows()->where('status', ImportRowStatus::Validated->value)->get() as $row) {
            $this->duplicateDetectionService->detect($row);
        }

        $rows = $batch->rows()->get();

        $batch->update(['status' => ImportBatchStatus::PreviewReady, 'previewed_at' => now()]);

        $this->auditService->record($batch, ImportAuditEventType::DryRunExecuted, metadata: ['row_count' => $rows->count()]);

        return new ImportPreviewResult(
            importBatchId: $batch->id,
            totalRows: $rows->count(),
            validRows: $rows->where('status', ImportRowStatus::Validated)->count(),
            invalidRows: $rows->where('status', ImportRowStatus::Invalid)->count(),
            duplicateRows: $rows->where('is_duplicate', true)->count(),
            sampleMappedRows: $rows->take(5)->pluck('mapped_data')->filter()->values()->all(),
        );
    }
}
