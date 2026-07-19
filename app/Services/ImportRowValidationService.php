<?php

namespace App\Services;

use App\Enums\ImportAuditEventType;
use App\Enums\ImportErrorSeverity;
use App\Enums\ImportRowStatus;
use App\Models\ImportBatch;
use App\Models\ImportRow;

/**
 * ImportRowValidationService — the only writer of import_errors
 * (project rule 5). Validates that every import_mappings-declared
 * required target_field is present and non-empty in the row's
 * mapped_data. A row with any Blocking-severity error becomes Invalid;
 * a row with only Warning-severity findings can still become Validated.
 *
 * import_batches carries FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_08_29_970003_prepare_row_level_security_and_force_rls_on_import_batches_table.php).
 * validateBatch() wraps its whole body (including this class's own
 * validateRow() loop) in a single runWithFirmContext() call, which
 * transitively covers validateRow()'s own $row->importBatch lazy load
 * too, since ambient context is active for the duration of the wrap.
 * validateRow() is only guaranteed tenant-safe when called from inside
 * validateBatch()'s wrap (or another already-active same-firm context)
 * — left public/unchanged rather than narrowed to
 * private/protected, since ImportRowValidationServiceTest.php calls it
 * directly today and visibility is out of this change's scope.
 */
class ImportRowValidationService
{
    public function __construct(
        private readonly ImportMappingService $mappingService,
        private readonly ImportAuditService $auditService,
    ) {
    }

    public function validateRow(ImportRow $row): ImportRow
    {
        $batch = $row->importBatch;
        $mappedData = $this->mappingService->applyMappingsToRawData($batch, $row->raw_data);

        $hasBlockingError = false;

        foreach ($batch->mappings as $mapping) {
            if (! $mapping->is_required) {
                continue;
            }

            $value = $mappedData[$mapping->target_field] ?? null;

            if ($value === null || $value === '') {
                $row->errors()->create([
                    'field' => $mapping->target_field,
                    'severity' => ImportErrorSeverity::Blocking,
                    'message' => "Required field '{$mapping->target_field}' is missing.",
                ]);
                $hasBlockingError = true;
            }
        }

        $row->update([
            'mapped_data' => $mappedData,
            'status' => $hasBlockingError ? ImportRowStatus::Invalid : ImportRowStatus::Validated,
        ]);

        return $row->fresh();
    }

    public function validateBatch(ImportBatch $batch): ImportBatch
    {
        return (new TenantContextService())->runWithFirmContext($batch->firm_id, function () use ($batch) {
            foreach ($batch->rows as $row) {
                $this->validateRow($row);
            }

            $this->auditService->record($batch, ImportAuditEventType::ValidationRun, metadata: [
                'row_count' => $batch->rows()->count(),
                'invalid_count' => $batch->rows()->where('status', ImportRowStatus::Invalid->value)->count(),
            ]);

            return $batch->fresh();
        });
    }
}
