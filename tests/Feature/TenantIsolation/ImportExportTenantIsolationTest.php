<?php

namespace Tests\Feature\TenantIsolation;

use App\Enums\ImportEntityType;
use App\Enums\ImportSourceType;
use App\Exceptions\TenantIsolationException;
use App\Models\ExportJob;
use App\Models\Firm;
use App\Models\ImportBatch;
use App\Models\MigrationProject;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use App\Services\TenantContextResolver;
use App\Services\TenantContextService;
use App\Services\TenantSafeImportExportPolicyService;
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
        $batchService = new ImportBatchService(new ImportAuditService);
        $batchService->create($firmA, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $batchService->create($firmB, ImportEntityType::Client, ImportSourceType::CsvUpload);

        // import_batches now carries FORCE ROW LEVEL SECURITY (Wave 9). The
        // service's own create() wrap already restored the database session
        // context by the time it returns, so a bare PHP-memory-only
        // activateForFirm() (as before) would leave the database session
        // context unset and the read below would return zero rows
        // (fail-closed), not the pre-FORCE 1 row. Establish BOTH layers of
        // context (PHP-memory, for BelongsToTenant's global scope, and the
        // database session setting, for RLS) via runWithFirmContext().
        $visibleBatches = (new TenantContextService)->runWithFirmContext(
            $firmA,
            fn () => ImportBatch::query()->get()
        );

        $this->assertCount(1, $visibleBatches);
        $this->assertSame($firmA->id, $visibleBatches->first()->firm_id);
    }

    public function test_export_job_global_scope_narrows_to_the_active_tenant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        ExportJob::factory()->forFirm($firmA)->create();
        ExportJob::factory()->forFirm($firmB)->create();

        // export_jobs now carries FORCE ROW LEVEL SECURITY (Wave 9). The
        // last factory create() above leaves the database session context
        // active for firmB (ExportJobFactory's context-hold override), but
        // this test must not rely on that incidental leftover state — make
        // the intent explicit with the same runWithFirmContext() wrap used
        // above, establishing both the PHP-memory context (for
        // BelongsToTenant's global scope) and the database session setting
        // (for RLS) for firmB before reading.
        $visibleJobs = (new TenantContextService)->runWithFirmContext(
            $firmB,
            fn () => ExportJob::query()->get()
        );

        $this->assertCount(1, $visibleJobs);
        $this->assertSame($firmB->id, $visibleJobs->first()->firm_id);
    }

    public function test_tenant_safe_policy_service_rejects_cross_firm_import_batch_access(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $batchService = new ImportBatchService(new ImportAuditService);
        $batch = $batchService->create($firmA, ImportEntityType::Client, ImportSourceType::CsvUpload);

        $this->expectException(TenantIsolationException::class);

        (new TenantSafeImportExportPolicyService)->assertImportBatchBelongsToFirm($batch, $firmB);
    }

    public function test_tenant_safe_policy_service_rejects_cross_firm_export_job_access(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $job = ExportJob::factory()->forFirm($firmA)->create();

        $this->expectException(TenantIsolationException::class);

        (new TenantSafeImportExportPolicyService)->assertExportJobBelongsToFirm($job, $firmB);
    }

    public function test_migration_project_global_scope_narrows_to_the_active_tenant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        MigrationProject::factory()->forFirm($firmA)->create();
        MigrationProject::factory()->forFirm($firmB)->create();

        // migration_projects now carries FORCE ROW LEVEL SECURITY (Wave 9);
        // see the two tests above for the full rationale.
        $visibleProjects = (new TenantContextService)->runWithFirmContext(
            $firmA,
            fn () => MigrationProject::query()->get()
        );

        $this->assertCount(1, $visibleProjects);
    }
}
