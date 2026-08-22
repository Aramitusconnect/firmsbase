<?php

namespace App\Services;

use App\Enums\ImportAuditEventType;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportRowStatus;
use App\Enums\ImportSourceType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\ImportBatch;
use App\Models\MigrationProject;
use App\Models\PlatformAdmin;

/**
 * ImportBatchService — the only writer of import_batches and (via
 * stageRows()) import_rows' initial staged state. Staging rows never
 * creates a production record for any entity — it only writes
 * import_rows with raw_data exactly as given (project rule 2/3).
 *
 * import_batches carries FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_08_29_970003_prepare_row_level_security_and_force_rls_on_import_batches_table.php),
 * so every write here runs under a runWithFirmContext() wrap, keyed on
 * the firm already known at each call site.
 */
class ImportBatchService
{
    public function __construct(
        private readonly ImportAuditService $auditService,
    ) {}

    public function create(
        Firm $firm,
        ImportEntityType $entityType,
        ImportSourceType $sourceType,
        ?MigrationProject $migrationProject = null,
        ?FirmUser $createdByFirmUser = null,
        ?PlatformAdmin $createdByPlatformAdmin = null,
    ): ImportBatch {
        $batch = (new TenantContextService)->runWithFirmContext($firm, fn () => ImportBatch::create([
            'firm_id' => $firm->id,
            'entity_type' => $entityType,
            'source_type' => $sourceType,
            'migration_project_id' => $migrationProject?->id,
            'status' => ImportBatchStatus::Draft,
            'created_by_firm_user_id' => $createdByFirmUser?->id,
            'created_by_platform_admin_id' => $createdByPlatformAdmin?->id,
        ]));

        $this->auditService->record($batch, ImportAuditEventType::BatchCreated);

        return $batch;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function stageRows(ImportBatch $batch, array $rows): ImportBatch
    {
        foreach (array_values($rows) as $index => $rawRow) {
            $batch->rows()->create([
                'row_number' => $index + 1,
                'raw_data' => $rawRow,
                'status' => ImportRowStatus::Staged,
            ]);
        }

        return (new TenantContextService)->runWithFirmContext($batch->firm_id, function () use ($batch) {
            $batch->update(['status' => ImportBatchStatus::Staged, 'staged_at' => now()]);

            return $batch->fresh();
        });
    }

    public function cancel(ImportBatch $batch): ImportBatch
    {
        return (new TenantContextService)->runWithFirmContext($batch->firm_id, function () use ($batch) {
            $batch->update(['status' => ImportBatchStatus::Cancelled, 'cancelled_at' => now()]);

            $this->auditService->record($batch, ImportAuditEventType::BatchCancelled);

            return $batch->fresh();
        });
    }
}
