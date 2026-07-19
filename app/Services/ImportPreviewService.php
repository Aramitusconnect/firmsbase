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
 *
 * preview()'s entire body is wrapped in a single runWithFirmContext()
 * call, keyed on $batch->firm_id (already an in-memory attribute on the
 * parameter — no extra query needed). This is required because
 * duplicateDetectionService->detect($row) internally does
 * `$row->importBatch` (a lazy load against the now-FORCE-RLS-protected
 * import_batches table) with no wrap of its own around that line — only
 * detect()'s per-entity-type helper methods (detectClient(),
 * detectContact(), etc.) wrap themselves. The outer wrap here safely
 * NESTS around validateBatch()'s own self-contained inner wrap (same
 * firm) — TenantContextService::runWithFirmContext() snapshots and
 * restores whatever context was active immediately before each call
 * (rather than unconditionally clearing), so nesting same-firm wraps is
 * safe by design. This exact pattern is already established elsewhere
 * in this wave (ImportApplyService::apply() nesting around applyRow()'s
 * own wraps; OffboardingRequestService::advance() nesting around
 * evaluateReadiness()'s wrap). validateBatch() itself is unchanged.
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
        return (new TenantContextService())->runWithFirmContext($batch->firm_id, function () use ($batch) {
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
        });
    }
}
