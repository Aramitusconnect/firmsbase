<?php

namespace Tests\Feature\Exports;

use App\Enums\ExportFileStatus;
use App\Models\ExportJob;
use App\Models\Firm;
use App\Services\ExportPackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportPackageServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExportPackageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExportPackageService();
    }

    public function test_generate_produces_governed_export_file_metadata_without_a_real_zip(): void
    {
        $firm = Firm::factory()->create();
        $job = ExportJob::factory()->forFirm($firm)->create();

        $file = $this->service->generate($job, 'client-export');

        $this->assertSame(ExportFileStatus::Generated, $file->status);
        $this->assertStringContainsString($firm->uuid, $file->simulated_storage_path);
        $this->assertFalse(\Illuminate\Support\Facades\Storage::exists($file->simulated_storage_path));
    }

    public function test_export_file_always_belongs_to_an_export_job(): void
    {
        $job = ExportJob::factory()->create();

        $file = $this->service->generate($job, 'package');

        $this->assertSame($job->id, $file->export_job_id);
    }

    public function test_export_never_includes_another_firms_data(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $jobA = ExportJob::factory()->forFirm($firmA)->create();

        $file = $this->service->generate($jobA, 'package');

        $this->assertStringContainsString($firmA->uuid, $file->simulated_storage_path);
        $this->assertStringNotContainsString($firmB->uuid, $file->simulated_storage_path);
        $this->assertSame($firmA->id, $file->exportJob->firm_id);
    }
}
