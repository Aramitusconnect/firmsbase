<?php

namespace Tests\Feature\Imports;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportRowStatus;
use App\Enums\ImportSourceType;
use App\Enums\RollbackRecordStatus;
use App\Models\Firm;
use App\Services\DocumentUploadPolicyService;
use App\Services\ImportApplyService;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use App\Services\ImportDocumentSafetyService;
use App\Services\ImportRollbackService;
use App\Services\InvoiceDraftingService;
use App\Services\PaymentPlanService;
use App\Services\VirusScan\FakeVirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportRollbackServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImportApplyService $applyService;

    private ImportBatchService $batchService;

    private ImportRollbackService $rollbackService;

    protected function setUp(): void
    {
        parent::setUp();
        $auditService = new ImportAuditService;
        $this->batchService = new ImportBatchService($auditService);
        $documentSafetyService = new ImportDocumentSafetyService(new DocumentUploadPolicyService, new FakeVirusScanner);
        $this->applyService = new ImportApplyService($documentSafetyService, $auditService, app(InvoiceDraftingService::class), app(PaymentPlanService::class));
        $this->rollbackService = new ImportRollbackService($auditService);
    }

    public function test_rollback_deletes_the_applied_production_record(): void
    {
        // import_batches now carries FORCE ROW LEVEL SECURITY (Wave 9).
        // Each writer service already restores database session context
        // to "none" once its own wrap exits, so a bare $batch->fresh()
        // call afterward would return null. Chain each service's own
        // already-fresh return value instead of re-fetching unwrapped.
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $batch = $this->batchService->stageRows($batch, [['display_name' => 'To Roll Back']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $confirmed = $this->applyService->confirmBatch($batch);
        $applied = $this->applyService->apply($confirmed);

        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseHas('clients', ['firm_id' => $firm->id, 'display_name' => 'To Roll Back']));

        $rolledBack = $this->rollbackService->rollbackBatch($applied);

        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseMissing('clients', ['firm_id' => $firm->id, 'display_name' => 'To Roll Back']));
        $this->assertSame(ImportBatchStatus::RolledBack, $rolledBack->status);
    }

    public function test_rollback_marks_rollback_records_rolled_back(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $batch = $this->batchService->stageRows($batch, [['display_name' => 'Client One']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $confirmed = $this->applyService->confirmBatch($batch);
        $applied = $this->applyService->apply($confirmed);

        $this->rollbackService->rollbackBatch($applied);

        $this->assertDatabaseHas('import_rollback_records', ['import_batch_id' => $batch->id, 'status' => RollbackRecordStatus::RolledBack->value]);
    }
}
