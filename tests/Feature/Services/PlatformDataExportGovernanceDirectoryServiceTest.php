<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\ExportJobStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\MigrationProjectStatus;
use App\Enums\OffboardingRequestStatus;
use App\Enums\PlatformRoleCode;
use App\Models\ExportJob;
use App\Models\Firm;
use App\Models\ImportBatch;
use App\Models\MigrationProject;
use App\Models\OffboardingExport;
use App\Models\OffboardingRequest;
use App\Models\PlatformAdmin;
use App\Services\PlatformDataExportGovernanceDirectoryService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * PlatformDataExportGovernanceDirectoryServiceTest — Phase 4 (FirmsVault
 * Platform Admin Control Center, "Operations, Governance, Support, and
 * Configuration"), Governance category, Data Exports module. Covers all
 * four sub-domains (ExportJob, OffboardingRequest+OffboardingExport,
 * ImportBatch, MigrationProject) and, critically, proves the
 * OffboardingExport RLS-gap discipline: exports are only ever reachable
 * through an already-firm-scoped OffboardingRequest, never via an
 * independent cross-firm scan.
 */
final class PlatformDataExportGovernanceDirectoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformDataExportGovernanceDirectoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PlatformDataExportGovernanceDirectoryService::class);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    // --- Export Jobs ---

    public function test_list_export_jobs_merges_across_every_firm(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Firm B']);

        ExportJob::factory()->forFirm($firmA)->create();
        ExportJob::factory()->forFirm($firmB)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listExportJobs($admin);

        $this->assertCount(2, $rows);
    }

    public function test_export_job_status_filter_narrows_the_list(): void
    {
        $firm = Firm::factory()->create();

        ExportJob::factory()->forFirm($firm)->create(['status' => ExportJobStatus::Completed->value]);
        ExportJob::factory()->forFirm($firm)->create(['status' => ExportJobStatus::Failed->value]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listExportJobs($admin, ['status' => ExportJobStatus::Failed->value]);

        $this->assertCount(1, $rows);
        $this->assertSame(ExportJobStatus::Failed->value, $rows->first()['status']);
    }

    public function test_find_export_job_resolves_only_under_the_correct_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $job = ExportJob::factory()->forFirm($firmA)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->assertNotNull($this->service->findExportJob($admin, $firmA, $job->id));
        $this->assertNull($this->service->findExportJob($admin, $firmB, $job->id));
    }

    public function test_export_job_deterministic_ordering_on_tie(): void
    {
        $firm = Firm::factory()->create();
        $tie = now();

        $jobs = collect(range(1, 3))->map(fn () => ExportJob::factory()->forFirm($firm)->create(['created_at' => $tie]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $first = $this->service->listExportJobs($admin)->pluck('id')->all();
        $second = $this->service->listExportJobs($admin)->pluck('id')->all();

        $this->assertSame($first, $second);
        $this->assertSame($jobs->sortByDesc('id')->pluck('id')->values()->all(), $first);
    }

    // --- Offboarding Requests + Exports (RLS-gap discipline) ---

    public function test_list_offboarding_requests_merges_across_every_firm(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Firm B']);

        OffboardingRequest::factory()->forFirm($firmA)->create();
        OffboardingRequest::factory()->forFirm($firmB)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listOffboardingRequests($admin);

        $this->assertCount(2, $rows);
    }

    public function test_offboarding_request_status_filter_narrows_the_list(): void
    {
        $firm = Firm::factory()->create();

        OffboardingRequest::factory()->forFirm($firm)->create(['status' => OffboardingRequestStatus::Completed]);
        OffboardingRequest::factory()->forFirm($firm)->create(['status' => OffboardingRequestStatus::Requested]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listOffboardingRequests($admin, ['status' => OffboardingRequestStatus::Completed->value]);

        $this->assertCount(1, $rows);
    }

    /**
     * The load-bearing proof for this module: OffboardingExport carries
     * no row level security of its own. Firm A's offboarding request's
     * export must NEVER appear when narrowing this listing to Firm B —
     * proving the export is genuinely reached only through the
     * already-firm-scoped parent, not an independent cross-firm scan
     * that would ignore firm boundaries entirely.
     */
    public function test_offboarding_exports_are_only_reachable_through_the_correct_firms_own_request(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Firm B']);

        $requestA = OffboardingRequest::factory()->forFirm($firmA)->create();
        $requestB = OffboardingRequest::factory()->forFirm($firmB)->create();

        $exportA = OffboardingExport::factory()->forOffboardingRequest($requestA)->verified()->create();
        $exportB = OffboardingExport::factory()->forOffboardingRequest($requestB)->verified()->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rowsForFirmAOnly = $this->service->listOffboardingRequests($admin, ['firm_uuid' => $firmA->uuid]);

        $this->assertCount(1, $rowsForFirmAOnly);
        $exportsSeen = $rowsForFirmAOnly->first()['exports'];
        $this->assertCount(1, $exportsSeen);
        $this->assertSame($exportA->id, $exportsSeen->first()['id']);
        $this->assertNotContains($exportB->id, $exportsSeen->pluck('id')->all(), 'Firm B\'s offboarding export must never leak into Firm A\'s narrowed listing.');
    }

    public function test_find_offboarding_request_includes_its_own_exports_only(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $requestA = OffboardingRequest::factory()->forFirm($firmA)->create();
        $requestB = OffboardingRequest::factory()->forFirm($firmB)->create();

        OffboardingExport::factory()->forOffboardingRequest($requestA)->create();
        OffboardingExport::factory()->forOffboardingRequest($requestB)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $found = $this->service->findOffboardingRequest($admin, $firmA, $requestA->id);
        $this->assertNotNull($found);
        $this->assertCount(1, $found['exports']);

        $notFoundUnderWrongFirm = $this->service->findOffboardingRequest($admin, $firmB, $requestA->id);
        $this->assertNull($notFoundUnderWrongFirm);
    }

    public function test_offboarding_request_deterministic_ordering_on_tie(): void
    {
        $firm = Firm::factory()->create();
        $tie = now();

        $requests = collect(range(1, 3))->map(fn () => OffboardingRequest::factory()->forFirm($firm)->create(['requested_at' => $tie]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $first = $this->service->listOffboardingRequests($admin)->pluck('id')->all();
        $second = $this->service->listOffboardingRequests($admin)->pluck('id')->all();

        $this->assertSame($first, $second);
        $this->assertSame($requests->sortByDesc('id')->pluck('id')->values()->all(), $first);
    }

    // --- Import Batches ---

    public function test_list_import_batches_merges_across_every_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        ImportBatch::factory()->forFirm($firmA)->create();
        ImportBatch::factory()->forFirm($firmB)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listImportBatches($admin);

        $this->assertCount(2, $rows);
    }

    public function test_import_batch_status_filter_narrows_the_list(): void
    {
        $firm = Firm::factory()->create();

        ImportBatch::factory()->forFirm($firm)->create(['status' => ImportBatchStatus::Applied->value]);
        ImportBatch::factory()->forFirm($firm)->create(['status' => ImportBatchStatus::Draft->value]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listImportBatches($admin, ['status' => ImportBatchStatus::Applied->value]);

        $this->assertCount(1, $rows);
    }

    public function test_find_import_batch_resolves_only_under_the_correct_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $batch = ImportBatch::factory()->forFirm($firmA)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->assertNotNull($this->service->findImportBatch($admin, $firmA, $batch->id));
        $this->assertNull($this->service->findImportBatch($admin, $firmB, $batch->id));
    }

    // --- Migration Projects ---

    public function test_list_migration_projects_merges_across_every_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        MigrationProject::factory()->forFirm($firmA)->create();
        MigrationProject::factory()->forFirm($firmB)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listMigrationProjects($admin);

        $this->assertCount(2, $rows);
    }

    public function test_migration_project_status_filter_narrows_the_list(): void
    {
        $firm = Firm::factory()->create();

        MigrationProject::factory()->forFirm($firm)->create(['status' => MigrationProjectStatus::Completed->value]);
        MigrationProject::factory()->forFirm($firm)->create(['status' => MigrationProjectStatus::Draft->value]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listMigrationProjects($admin, ['status' => MigrationProjectStatus::Completed->value]);

        $this->assertCount(1, $rows);
    }

    public function test_find_migration_project_resolves_only_under_the_correct_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $project = MigrationProject::factory()->forFirm($firmA)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->assertNotNull($this->service->findMigrationProject($admin, $firmA, $project->id));
        $this->assertNull($this->service->findMigrationProject($admin, $firmB, $project->id));
    }

    // --- Access gate ---

    public function test_a_role_without_governance_access_is_denied_for_every_sub_domain(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);

        foreach (['listExportJobs', 'listOffboardingRequests', 'listImportBatches', 'listMigrationProjects'] as $method) {
            try {
                $this->service->{$method}($admin);
                $this->fail("{$method}() should have thrown RuntimeException for a role without governance access.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
