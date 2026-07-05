<?php

namespace Tests\Feature\TenantIsolation;

use App\Enums\ImportEntityType;
use App\Enums\ImportSourceType;
use App\Models\Firm;
use App\Services\ImportBatchService;
use App\Services\ImportAuditService;
use App\Services\TenantSafeImportExportPolicyService;
use App\Exceptions\TenantIsolationException;
use App\Models\ExportJob;
use App\Services\TenantContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ImportExportTenantIsolationTest — mandatory per approved Phase 8
 * tenant rules (#4). Confirms ImportBatch/ExportJob application-level
 * isolation both via BelongsToTenant's global scope and via
 * TenantSafeImportExportPolicyService's explicit guard.
 */
class ImportExportTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContextResolver::clear();
        parent::tearDown();
    }

    public function test_import_batch_global_scope_narrows_to_the_active_tenant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $batchService = new ImportBatchService(new ImportAuditService());
        $batchService->create($firmA, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $batchService->create($firmB, ImportEntityType::Client, ImportSourceType::CsvUpload);

        (new TenantContextResolver())->activateForFirm($firmA);

        $visibleBatches = \App\Models\ImportBatch::query()->get();

        $this->assertCount(1, $visibleBatches);
        $this->assertSame($firmA->id, $visibleBatches->first()->firm_id);
    }

    public function test_export_job_global_scope_narrows_to_the_active_tenant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        ExportJob::factory()->forFirm($firmA)->create();
        ExportJob::factory()->forFirm($firmB)->create();

        (new TenantContextResolver())->activateForFirm($firmB);

        $visibleJobs = ExportJob::query()->get();

        $this->assertCount(1, $visibleJobs);
        $this->assertSame($firmB->id, $visibleJobs->first()->firm_id);
    }

    public function test_tenant_safe_policy_service_rejects_cross_firm_import_batch_access(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $batchService = new ImportBatchService(new ImportAuditService());
        $batch = $batchService->create($firmA, ImportEntityType::Client, ImportSourceType::CsvUpload);

        $this->expectException(TenantIsolationException::class);

        (new TenantSafeImportExportPolicyService())->assertImportBatchBelongsToFirm($batch, $firmB);
    }

    public function test_tenant_safe_policy_service_rejects_cross_firm_export_job_access(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $job = ExportJob::factory()->forFirm($firmA)->create();

        $this->expectException(TenantIsolationException::class);

        (new TenantSafeImportExportPolicyService())->assertExportJobBelongsToFirm($job, $firmB);
    }

    public function test_migration_project_global_scope_narrows_to_the_active_tenant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        \App\Models\MigrationProject::factory()->forFirm($firmA)->create();
        \App\Models\MigrationProject::factory()->forFirm($firmB)->create();

        (new TenantContextResolver())->activateForFirm($firmA);

        $this->assertCount(1, \App\Models\MigrationProject::query()->get());
    }
}
