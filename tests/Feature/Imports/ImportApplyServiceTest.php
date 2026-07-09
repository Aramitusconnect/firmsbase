<?php

namespace Tests\Feature\Imports;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportRowStatus;
use App\Enums\ImportSourceType;
use App\Models\Firm;
use App\Services\ImportApplyService;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use App\Services\ImportDocumentSafetyService;
use App\Services\DocumentUploadPolicyService;
use App\Services\VirusScan\FakeVirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportApplyServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImportApplyService $service;
    private ImportBatchService $batchService;

    protected function setUp(): void
    {
        parent::setUp();
        $auditService = new ImportAuditService();
        $this->batchService = new ImportBatchService($auditService);
        $documentSafetyService = new ImportDocumentSafetyService(new DocumentUploadPolicyService(), new FakeVirusScanner());
        $this->service = new ImportApplyService($documentSafetyService, $auditService);
    }

    public function test_confirmed_rows_apply_and_create_a_production_client_record(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $this->batchService->stageRows($batch, [['display_name' => 'Applied Client']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);

        $this->service->confirmBatch($batch->fresh());
        $applied = $this->service->apply($batch->fresh());

        $this->assertSame(ImportBatchStatus::Applied, $applied->status);
        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseHas('clients', ['firm_id' => $firm->id, 'display_name' => 'Applied Client']));

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Applied, $row->status);
        $this->assertNotNull($row->applied_record_id);
    }

    public function test_apply_never_runs_on_rows_that_are_not_confirmed(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $this->batchService->stageRows($batch, [['display_name' => 'Never Applied']]);
        // Deliberately left in Staged status — never validated/confirmed.

        $this->service->apply($batch->fresh());

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_apply_creates_a_pending_rollback_record_for_each_applied_row(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $this->batchService->stageRows($batch, [['display_name' => 'Rollback Me']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $this->service->confirmBatch($batch->fresh());

        $this->service->apply($batch->fresh());

        $this->assertDatabaseHas('import_rollback_records', ['import_batch_id' => $batch->id, 'status' => 'pending']);
    }

    public function test_missing_required_field_fails_the_row_instead_of_creating_a_broken_record(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $this->batchService->stageRows($batch, [[]]); // no display_name at all
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $this->service->confirmBatch($batch->fresh());

        $this->service->apply($batch->fresh());

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Failed, $row->status);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_conflict_record_and_template_rows_are_skipped_not_fabricated(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Template, ImportSourceType::CsvUpload);
        $this->batchService->stageRows($batch, [['name' => 'Some template']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $this->service->confirmBatch($batch->fresh());

        $this->service->apply($batch->fresh());

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Skipped, $row->status);
    }
}
