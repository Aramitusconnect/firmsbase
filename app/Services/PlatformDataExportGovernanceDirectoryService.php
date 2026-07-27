<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExportJob;
use App\Models\Firm;
use App\Models\ImportBatch;
use App\Models\MigrationProject;
use App\Models\OffboardingExport;
use App\Models\OffboardingRequest;
use App\Models\PlatformAdmin;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * PlatformDataExportGovernanceDirectoryService — Phase 4 (FirmsVault
 * Platform Admin Control Center, "Operations, Governance, Support, and
 * Configuration"), Governance category, Data Exports module. The
 * cross-firm read path behind ExportJobResource, OffboardingRequestResource,
 * ImportBatchResource, MigrationProjectResource — four distinct backend
 * domains disambiguated exactly per the Phase 4 architecture map (§B.4):
 * (1) exports proper (`export_jobs`), (2) offboarding
 * (`offboarding_requests` + `offboarding_exports`), (3) import/migration
 * (`import_batches`/`migration_projects` — data coming IN, a distinct
 * direction from exports). `FleetMigrationOrchestrationService`
 * (fleet_migration_runs/fleet_migration_instance_status) is NOT covered
 * here — it is a Deployments-domain concept (simulated fleet-wide
 * deployment-mode migration), not a client-data export/migration
 * concept; both this Governance investigation and the parallel
 * Operations investigation independently confirmed this and excluded it
 * from Data Exports.
 *
 * Architectural constraint (see PlatformFirmUserDirectoryService's own
 * docblock, the original template): `export_jobs`, `offboarding_requests`,
 * `import_batches`, `migration_projects` all carry permanent FORCE ROW
 * LEVEL SECURITY, firm-scoped only. The per-firm loop under
 * runWithFirmContext() is the same pattern every other cross-firm
 * directory service in this mission uses.
 *
 * `offboarding_exports` is a DISCLOSED RLS GAP, not a table this class
 * treats as safe-by-design: RowLevelSecurityCoverageMappingService
 * classifies it `Uncertain` and explicitly excludes it from
 * EXEMPT_TABLES — both `offboarding_request_id` and `export_job_id` are
 * nullable, so no FK reliably establishes tenant ownership for every
 * row, and the table carries NO row level security of its own at all.
 * Querying `offboarding_exports` on its own, with no firm context,
 * would not be blocked by anything — that is the risk this class avoids
 * by construction: offboardingExportsForRequests() below is NEVER
 * called with request ids from anywhere other than the immediately
 * preceding, already-firm-scoped `offboarding_requests` read inside the
 * SAME per-firm loop iteration — i.e. always joined through the
 * RLS-covered parent, never queried blind. No public method on this
 * class accepts a bare OffboardingExport id or exposes an
 * unconstrained `offboarding_exports` listing.
 *
 * "No real file is ever produced" for ANY export shown here —
 * ExportJobService/OffboardingExportService's own docblocks are
 * explicit: `package_manifest_json`/similar fields are declared lists
 * of data-category strings, never a real ZIP or storage write. Every
 * row shape returned below carries only status/manifest metadata, never
 * anything implying a downloadable artifact exists.
 *
 * `ExportJob::markInProgress()/markCompleted()/markFailed()` are
 * deliberately NOT exposed as admin-facing mutations anywhere in this
 * phase — all three accept no actor parameter at all (system-only
 * lifecycle transitions with no attribution capability), unlike every
 * other mutation this phase exposes (which are all real,
 * already-actor-typed methods). Exposing them here would fabricate an
 * admin-facing capability this backend was never designed to attribute.
 */
final class PlatformDataExportGovernanceDirectoryService
{
    private const PER_FIRM_LIMIT = 200;

    private const EXPORT_JOB_COLUMNS = [
        'id', 'uuid', 'export_type', 'status', 'requested_by_firm_user_id',
        'requested_by_platform_admin_id', 'reason', 'legal_hold_checked',
        'retention_checked', 'offboarding_checked', 'started_at',
        'completed_at', 'failed_reason', 'created_at',
    ];

    private const OFFBOARDING_REQUEST_COLUMNS = [
        'id', 'uuid', 'status', 'reason', 'requested_by_platform_admin_id',
        'requested_at', 'completed_at', 'cancelled_at', 'cancelled_reason', 'created_at',
    ];

    private const IMPORT_BATCH_COLUMNS = [
        'id', 'uuid', 'entity_type', 'source_type', 'migration_project_id', 'status',
        'created_by_firm_user_id', 'created_by_platform_admin_id', 'staged_at',
        'previewed_at', 'confirmed_at', 'applied_at', 'rolled_back_at', 'cancelled_at', 'created_at',
    ];

    private const MIGRATION_PROJECT_COLUMNS = [
        'id', 'uuid', 'source_type', 'status', 'notes', 'started_at', 'completed_at',
        'created_by_firm_user_id', 'created_by_platform_admin_id', 'created_at',
    ];

    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function assertCanAccess(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canAccessGovernance($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to access governance data.');
        }
    }

    // ---------------------------------------------------------------
    // Export Jobs
    // ---------------------------------------------------------------

    /**
     * @param  array{firm_uuid?: ?string, status?: ?string, export_type?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function listExportJobs(PlatformAdmin $admin, array $filters = []): Collection
    {
        $this->assertCanAccess($admin);

        $status = $filters['status'] ?? null;
        $exportType = $filters['export_type'] ?? null;

        $rows = collect();

        foreach ($this->firmsForFilter($filters['firm_uuid'] ?? null) as $firm) {
            $jobs = $this->tenantContext->runWithFirmContext($firm, fn () => ExportJob::query()
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->when($exportType !== null, fn ($q) => $q->where('export_type', $exportType))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::PER_FIRM_LIMIT)
                ->get(self::EXPORT_JOB_COLUMNS));

            foreach ($jobs as $job) {
                $rows->push($this->exportJobRow($firm, $job));
            }
        }

        return $this->sortDeterministically($rows, 'created_at')->values();
    }

    public function findExportJob(PlatformAdmin $admin, Firm $firm, int $id): ?array
    {
        $this->assertCanAccess($admin);

        $job = $this->tenantContext->runWithFirmContext($firm, fn () => ExportJob::query()
            ->where('id', $id)
            ->first(self::EXPORT_JOB_COLUMNS));

        return $job === null ? null : $this->exportJobRow($firm, $job);
    }

    /**
     * @return array<string, mixed>
     */
    private function exportJobRow(Firm $firm, ExportJob $job): array
    {
        return [
            'id' => $job->id,
            'uuid' => $job->uuid,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'export_type' => $job->export_type?->value,
            'status' => $job->status?->value,
            'requested_by_firm_user_id' => $job->requested_by_firm_user_id,
            'requested_by_platform_admin_id' => $job->requested_by_platform_admin_id,
            'reason' => $job->reason,
            'legal_hold_checked' => (bool) $job->legal_hold_checked,
            'retention_checked' => (bool) $job->retention_checked,
            'offboarding_checked' => (bool) $job->offboarding_checked,
            'started_at' => $job->started_at,
            'completed_at' => $job->completed_at,
            'failed_reason' => $job->failed_reason,
            'created_at' => $job->created_at,
        ];
    }

    // ---------------------------------------------------------------
    // Offboarding Requests (+ nested Offboarding Exports, joined
    // through the parent — see class docblock's RLS-gap handling).
    // ---------------------------------------------------------------

    /**
     * @param  array{firm_uuid?: ?string, status?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function listOffboardingRequests(PlatformAdmin $admin, array $filters = []): Collection
    {
        $this->assertCanAccess($admin);

        $status = $filters['status'] ?? null;

        $rows = collect();

        foreach ($this->firmsForFilter($filters['firm_uuid'] ?? null) as $firm) {
            $requests = $this->tenantContext->runWithFirmContext($firm, fn () => OffboardingRequest::query()
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->orderByDesc('requested_at')
                ->orderByDesc('id')
                ->limit(self::PER_FIRM_LIMIT)
                ->get(self::OFFBOARDING_REQUEST_COLUMNS));

            // Joined through the parent per this class's own RLS-gap
            // discipline: these ids were just resolved under this
            // firm's own FORCE-RLS-protected context above — never a
            // blind, unscoped offboarding_exports query.
            $exportCounts = $this->offboardingExportsForRequests($requests->pluck('id'));

            foreach ($requests as $request) {
                $rows->push($this->offboardingRequestRow($firm, $request, $exportCounts->get($request->id, collect())));
            }
        }

        return $this->sortDeterministically($rows, 'requested_at')->values();
    }

    public function findOffboardingRequest(PlatformAdmin $admin, Firm $firm, int $id): ?array
    {
        $this->assertCanAccess($admin);

        $request = $this->tenantContext->runWithFirmContext($firm, fn () => OffboardingRequest::query()
            ->where('id', $id)
            ->first(self::OFFBOARDING_REQUEST_COLUMNS));

        if ($request === null) {
            return null;
        }

        $exports = $this->offboardingExportsForRequests(collect([$request->id]))->get($request->id, collect());

        return $this->offboardingRequestRow($firm, $request, $exports);
    }

    /**
     * ONE batched query, keyed only by ids already established as
     * belonging to the calling firm (see class docblock) — never called
     * with an unbounded/unscoped id set.
     *
     * @param  Collection<int, int>  $requestIds
     * @return Collection<int, Collection<int, array<string, mixed>>> keyed by offboarding_request_id
     */
    private function offboardingExportsForRequests(Collection $requestIds): Collection
    {
        $ids = $requestIds->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return OffboardingExport::query()
            ->whereIn('offboarding_request_id', $ids)
            ->orderByDesc('id')
            ->get([
                'id', 'uuid', 'offboarding_request_id', 'deletion_request_id', 'export_job_id',
                'status', 'package_manifest_json', 'generated_at', 'verified_at',
                'verified_by_platform_admin_id', 'expires_at',
            ])
            ->map(fn (OffboardingExport $export): array => [
                'id' => $export->id,
                'uuid' => $export->uuid,
                'offboarding_request_id' => $export->offboarding_request_id,
                'export_job_id' => $export->export_job_id,
                'status' => $export->status?->value,
                'package_manifest_json' => $export->package_manifest_json,
                'generated_at' => $export->generated_at,
                'verified_at' => $export->verified_at,
                'verified_by_platform_admin_id' => $export->verified_by_platform_admin_id,
                'expires_at' => $export->expires_at,
            ])
            ->groupBy('offboarding_request_id');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $exports
     * @return array<string, mixed>
     */
    private function offboardingRequestRow(Firm $firm, OffboardingRequest $request, Collection $exports): array
    {
        return [
            'id' => $request->id,
            'uuid' => $request->uuid,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'status' => $request->status?->value,
            'reason' => $request->reason,
            'requested_by_platform_admin_id' => $request->requested_by_platform_admin_id,
            'requested_at' => $request->requested_at,
            'completed_at' => $request->completed_at,
            'cancelled_at' => $request->cancelled_at,
            'cancelled_reason' => $request->cancelled_reason,
            'exports' => $exports->values(),
        ];
    }

    // ---------------------------------------------------------------
    // Import Batches — read-only status visibility.
    // ---------------------------------------------------------------

    /**
     * @param  array{firm_uuid?: ?string, status?: ?string, entity_type?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function listImportBatches(PlatformAdmin $admin, array $filters = []): Collection
    {
        $this->assertCanAccess($admin);

        $status = $filters['status'] ?? null;
        $entityType = $filters['entity_type'] ?? null;

        $rows = collect();

        foreach ($this->firmsForFilter($filters['firm_uuid'] ?? null) as $firm) {
            $batches = $this->tenantContext->runWithFirmContext($firm, fn () => ImportBatch::query()
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->when($entityType !== null, fn ($q) => $q->where('entity_type', $entityType))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::PER_FIRM_LIMIT)
                ->get(self::IMPORT_BATCH_COLUMNS));

            foreach ($batches as $batch) {
                $rows->push($this->importBatchRow($firm, $batch));
            }
        }

        return $this->sortDeterministically($rows, 'created_at')->values();
    }

    public function findImportBatch(PlatformAdmin $admin, Firm $firm, int $id): ?array
    {
        $this->assertCanAccess($admin);

        $batch = $this->tenantContext->runWithFirmContext($firm, fn () => ImportBatch::query()
            ->where('id', $id)
            ->first(self::IMPORT_BATCH_COLUMNS));

        return $batch === null ? null : $this->importBatchRow($firm, $batch);
    }

    /**
     * @return array<string, mixed>
     */
    private function importBatchRow(Firm $firm, ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'uuid' => $batch->uuid,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'entity_type' => $batch->entity_type?->value,
            'source_type' => $batch->source_type?->value,
            'migration_project_id' => $batch->migration_project_id,
            'status' => $batch->status?->value,
            'created_by_firm_user_id' => $batch->created_by_firm_user_id,
            'created_by_platform_admin_id' => $batch->created_by_platform_admin_id,
            'staged_at' => $batch->staged_at,
            'previewed_at' => $batch->previewed_at,
            'confirmed_at' => $batch->confirmed_at,
            'applied_at' => $batch->applied_at,
            'rolled_back_at' => $batch->rolled_back_at,
            'cancelled_at' => $batch->cancelled_at,
            'created_at' => $batch->created_at,
        ];
    }

    // ---------------------------------------------------------------
    // Migration Projects — read-only status visibility.
    // ---------------------------------------------------------------

    /**
     * @param  array{firm_uuid?: ?string, status?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function listMigrationProjects(PlatformAdmin $admin, array $filters = []): Collection
    {
        $this->assertCanAccess($admin);

        $status = $filters['status'] ?? null;

        $rows = collect();

        foreach ($this->firmsForFilter($filters['firm_uuid'] ?? null) as $firm) {
            $projects = $this->tenantContext->runWithFirmContext($firm, fn () => MigrationProject::query()
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::PER_FIRM_LIMIT)
                ->get(self::MIGRATION_PROJECT_COLUMNS));

            foreach ($projects as $project) {
                $rows->push($this->migrationProjectRow($firm, $project));
            }
        }

        return $this->sortDeterministically($rows, 'created_at')->values();
    }

    public function findMigrationProject(PlatformAdmin $admin, Firm $firm, int $id): ?array
    {
        $this->assertCanAccess($admin);

        $project = $this->tenantContext->runWithFirmContext($firm, fn () => MigrationProject::query()
            ->where('id', $id)
            ->first(self::MIGRATION_PROJECT_COLUMNS));

        return $project === null ? null : $this->migrationProjectRow($firm, $project);
    }

    /**
     * @return array<string, mixed>
     */
    private function migrationProjectRow(Firm $firm, MigrationProject $project): array
    {
        return [
            'id' => $project->id,
            'uuid' => $project->uuid,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'source_type' => $project->source_type?->value,
            'status' => $project->status?->value,
            'notes' => $project->notes,
            'started_at' => $project->started_at,
            'completed_at' => $project->completed_at,
            'created_by_firm_user_id' => $project->created_by_firm_user_id,
            'created_by_platform_admin_id' => $project->created_by_platform_admin_id,
            'created_at' => $project->created_at,
        ];
    }

    // ---------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------

    /**
     * @return Collection<int, Firm>
     */
    private function firmsForFilter(?string $firmUuid): Collection
    {
        return Firm::query()
            ->when($firmUuid !== null, fn ($q) => $q->where('uuid', $firmUuid))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortDeterministically(Collection $rows, string $timestampKey): Collection
    {
        $items = $rows->all();

        usort($items, function (array $a, array $b) use ($timestampKey): int {
            $aTime = $a[$timestampKey]?->timestamp ?? 0;
            $bTime = $b[$timestampKey]?->timestamp ?? 0;

            return $bTime <=> $aTime ?: $b['id'] <=> $a['id'];
        });

        return collect($items)->values();
    }
}
