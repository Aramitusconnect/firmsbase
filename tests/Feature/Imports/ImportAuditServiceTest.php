<?php

namespace Tests\Feature\Imports;

use App\Enums\ImportAuditEventType;
use App\Models\ImportBatch;
use App\Services\ImportAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImportAuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImportAuditService();
    }

    public function test_record_writes_an_import_audit_event(): void
    {
        $batch = ImportBatch::factory()->create();

        $this->service->record($batch, ImportAuditEventType::BatchCreated, metadata: ['foo' => 'bar']);

        $this->assertDatabaseHas('import_audit_events', [
            'import_batch_id' => $batch->id,
            'event_type' => 'batch_created',
        ]);
    }

    public function test_audit_events_have_no_uuid_column(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('import_audit_events');

        $this->assertNotContains('uuid', $columns);
    }
}
