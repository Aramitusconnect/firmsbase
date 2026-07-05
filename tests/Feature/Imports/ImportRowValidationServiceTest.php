<?php

namespace Tests\Feature\Imports;

use App\Enums\ImportRowStatus;
use App\Models\ImportBatch;
use App\Services\ImportAuditService;
use App\Services\ImportMappingService;
use App\Services\ImportRowValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportRowValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImportRowValidationService $service;
    private ImportMappingService $mappingService;

    protected function setUp(): void
    {
        parent::setUp();
        $auditService = new ImportAuditService();
        $this->mappingService = new ImportMappingService($auditService);
        $this->service = new ImportRowValidationService($this->mappingService, $auditService);
    }

    public function test_missing_required_field_creates_an_import_error_and_invalid_status(): void
    {
        $batch = ImportBatch::factory()->create();
        $this->mappingService->saveMappings($batch, [
            ['source_field' => 'email', 'target_field' => 'email', 'is_required' => true],
        ]);
        $row = $batch->rows()->create(['row_number' => 1, 'raw_data' => [], 'status' => 'staged']);

        $validated = $this->service->validateRow($row);

        $this->assertSame(ImportRowStatus::Invalid, $validated->status);
        $this->assertDatabaseHas('import_errors', ['import_row_id' => $row->id, 'severity' => 'blocking']);
    }

    public function test_row_with_all_required_fields_becomes_validated(): void
    {
        $batch = ImportBatch::factory()->create();
        $this->mappingService->saveMappings($batch, [
            ['source_field' => 'email', 'target_field' => 'email', 'is_required' => true],
        ]);
        $row = $batch->rows()->create(['row_number' => 1, 'raw_data' => ['email' => 'a@b.test'], 'status' => 'staged']);

        $validated = $this->service->validateRow($row);

        $this->assertSame(ImportRowStatus::Validated, $validated->status);
        $this->assertDatabaseCount('import_errors', 0);
    }
}
