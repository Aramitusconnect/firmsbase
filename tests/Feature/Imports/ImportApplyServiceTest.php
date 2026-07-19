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
        // import_batches now carries FORCE ROW LEVEL SECURITY (Wave 9).
        // Each writer service already restores database session context
        // to "none" once its own wrap exits, so a bare $batch->fresh()
        // call afterward would return null. Chain each service's own
        // already-fresh return value instead of re-fetching unwrapped.
        $batch = $this->batchService->stageRows($batch, [['display_name' => 'Applied Client']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);

        $confirmed = $this->service->confirmBatch($batch);
        $applied = $this->service->apply($confirmed);

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
        // Chain stageRows()'s own already-fresh return value rather than
        // a bare, unwrapped $batch->fresh() re-fetch (see the comment on
        // the first test in this file for the full FORCE RLS rationale).
        $batch = $this->batchService->stageRows($batch, [['display_name' => 'Never Applied']]);
        // Deliberately left in Staged status — never validated/confirmed.

        $this->service->apply($batch);

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_apply_creates_a_pending_rollback_record_for_each_applied_row(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $batch = $this->batchService->stageRows($batch, [['display_name' => 'Rollback Me']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $confirmed = $this->service->confirmBatch($batch);

        $this->service->apply($confirmed);

        $this->assertDatabaseHas('import_rollback_records', ['import_batch_id' => $batch->id, 'status' => 'pending']);
    }

    public function test_missing_required_field_fails_the_row_instead_of_creating_a_broken_record(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $batch = $this->batchService->stageRows($batch, [[]]); // no display_name at all
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $confirmed = $this->service->confirmBatch($batch);

        $this->service->apply($confirmed);

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Failed, $row->status);
        $this->assertDatabaseCount('clients', 0);
    }

    /**
     * Section 39A-3L Phase B5 proof: createRecordFor()'s Contact/Party
     * create arms are each now wrapped in runWithFirmContext() (in
     * preparation for a future FORCE ROW LEVEL SECURITY activation on
     * those two tables, not yet active in this batch). No existing
     * test in this file exercised the Contact or Party entity types at
     * all before this batch — only Client. Assert the wrap did not
     * break normal apply behavior for either type.
     */
    public function test_confirmed_contact_row_applies_and_creates_a_production_contact_record(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Contact, ImportSourceType::CsvUpload);
        $batch = $this->batchService->stageRows($batch, [['name' => 'Applied Contact', 'email' => 'applied.contact@example.test']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);

        $confirmed = $this->service->confirmBatch($batch);
        $applied = $this->service->apply($confirmed);

        $this->assertSame(ImportBatchStatus::Applied, $applied->status);
        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseHas('contacts', [
            'firm_id' => $firm->id,
            'name' => 'Applied Contact',
            'email' => 'applied.contact@example.test',
        ]));

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Applied, $row->status);
        $this->assertSame(\App\Models\Contact::class, $row->applied_record_type);
        $this->assertNotNull($row->applied_record_id);
    }

    public function test_confirmed_party_row_applies_and_creates_a_production_party_record(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Party, ImportSourceType::CsvUpload);
        $batch = $this->batchService->stageRows($batch, [['name' => 'Applied Party', 'entity_type' => 'individual']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);

        $confirmed = $this->service->confirmBatch($batch);
        $applied = $this->service->apply($confirmed);

        $this->assertSame(ImportBatchStatus::Applied, $applied->status);
        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseHas('parties', [
            'firm_id' => $firm->id,
            'name' => 'Applied Party',
        ]));

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Applied, $row->status);
        $this->assertSame(\App\Models\Party::class, $row->applied_record_type);
        $this->assertNotNull($row->applied_record_id);
    }

    public function test_conflict_record_and_template_rows_are_skipped_not_fabricated(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Template, ImportSourceType::CsvUpload);
        $batch = $this->batchService->stageRows($batch, [['name' => 'Some template']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $confirmed = $this->service->confirmBatch($batch);

        $this->service->apply($confirmed);

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Skipped, $row->status);
    }
}
