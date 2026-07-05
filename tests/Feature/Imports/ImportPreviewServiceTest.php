<?php

namespace Tests\Feature\Imports;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportSourceType;
use App\Models\Firm;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use App\Services\ImportDuplicateDetectionService;
use App\Services\ImportMappingService;
use App\Services\ImportPreviewService;
use App\Services\ImportRowValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportPreviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImportPreviewService $service;
    private ImportBatchService $batchService;
    private ImportMappingService $mappingService;

    protected function setUp(): void
    {
        parent::setUp();
        $auditService = new ImportAuditService();
        $this->mappingService = new ImportMappingService($auditService);
        $this->batchService = new ImportBatchService($auditService);
        $this->service = new ImportPreviewService(
            new ImportRowValidationService($this->mappingService, $auditService),
            new ImportDuplicateDetectionService($auditService),
            $auditService,
        );
    }

    public function test_preview_does_not_create_any_production_record(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $this->mappingService->saveMappings($batch, [
            ['source_field' => 'display_name', 'target_field' => 'display_name', 'is_required' => true],
        ]);
        $this->batchService->stageRows($batch, [['display_name' => 'Alice Firm']]);

        $result = $this->service->preview($batch->fresh());

        $this->assertSame(1, $result->totalRows);
        $this->assertDatabaseCount('clients', 0);
        $this->assertSame(ImportBatchStatus::PreviewReady, $batch->fresh()->status);
    }

    public function test_preview_summarizes_valid_invalid_and_duplicate_counts(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->batchService->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $this->mappingService->saveMappings($batch, [
            ['source_field' => 'display_name', 'target_field' => 'display_name', 'is_required' => true],
        ]);
        $this->batchService->stageRows($batch, [
            ['display_name' => 'Valid Row'],
            [], // missing required field
        ]);

        $result = $this->service->preview($batch->fresh());

        $this->assertSame(2, $result->totalRows);
        $this->assertSame(1, $result->validRows);
        $this->assertSame(1, $result->invalidRows);
    }
}
