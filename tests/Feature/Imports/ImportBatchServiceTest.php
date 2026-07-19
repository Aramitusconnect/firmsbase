<?php

namespace Tests\Feature\Imports;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportSourceType;
use App\Models\Firm;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportBatchServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImportBatchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImportBatchService(new ImportAuditService());
    }

    public function test_create_writes_a_draft_batch_and_an_audit_event(): void
    {
        $firm = Firm::factory()->create();

        $batch = $this->service->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);

        $this->assertSame(ImportBatchStatus::Draft, $batch->status);
        $this->assertDatabaseHas('import_audit_events', ['import_batch_id' => $batch->id, 'event_type' => 'batch_created']);
    }

    public function test_stage_rows_writes_raw_data_unchanged(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->service->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);

        // import_batches now carries FORCE ROW LEVEL SECURITY (Wave 9).
        // stageRows()'s own wrap already restores the database session
        // context to "none" by the time it returns, so a bare
        // $batch->fresh() call afterward (with no ambient context active)
        // would return null. stageRows() already returns the fresh,
        // in-memory batch itself — capture and use that directly instead
        // of a redundant unwrapped re-fetch.
        $staged = $this->service->stageRows($batch, [
            ['name' => 'Alice', 'email' => 'alice@example.test'],
            ['name' => 'Bob', 'email' => 'bob@example.test'],
        ]);

        $this->assertSame(ImportBatchStatus::Staged, $staged->status);
        $this->assertDatabaseCount('import_rows', 2);
        $this->assertDatabaseHas('import_rows', ['row_number' => 1]);
    }

    public function test_cancel_marks_batch_cancelled(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->service->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);

        $cancelled = $this->service->cancel($batch);

        $this->assertSame(ImportBatchStatus::Cancelled, $cancelled->status);
    }
}
