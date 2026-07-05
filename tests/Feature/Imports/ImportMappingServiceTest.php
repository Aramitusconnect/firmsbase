<?php

namespace Tests\Feature\Imports;

use App\Models\ImportBatch;
use App\Services\ImportAuditService;
use App\Services\ImportMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImportMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImportMappingService(new ImportAuditService());
    }

    public function test_save_mappings_persists_and_can_be_updated(): void
    {
        $batch = ImportBatch::factory()->create();

        $this->service->saveMappings($batch, [
            ['source_field' => 'Full Name', 'target_field' => 'display_name', 'is_required' => true],
            ['source_field' => 'Email', 'target_field' => 'email'],
        ]);

        $this->assertDatabaseCount('import_mappings', 2);

        $this->service->saveMappings($batch, [
            ['source_field' => 'Full Name', 'target_field' => 'display_name', 'is_required' => false],
        ]);

        $this->assertDatabaseCount('import_mappings', 2);
        $this->assertDatabaseHas('import_mappings', ['source_field' => 'Full Name', 'is_required' => false]);
    }

    public function test_apply_mappings_to_raw_data_renames_fields(): void
    {
        $batch = ImportBatch::factory()->create();
        $this->service->saveMappings($batch, [
            ['source_field' => 'Full Name', 'target_field' => 'display_name'],
        ]);

        $mapped = $this->service->applyMappingsToRawData($batch->fresh(), ['Full Name' => 'Alice']);

        $this->assertSame(['display_name' => 'Alice'], $mapped);
    }
}
