<?php

namespace App\Services;

use App\Enums\MigrationProjectStatus;
use App\Enums\MigrationSourceType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\MigrationProject;
use App\Models\PlatformAdmin;

/**
 * MigrationProjectService — the only writer of migration_projects.
 * Source types are guides/labels only (project rule) — this service
 * never makes a real external API call for any MigrationSourceType
 * case.
 *
 * migration_projects carries FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_08_29_970002_prepare_row_level_security_and_force_rls_on_migration_projects_table.php),
 * so every write here runs under a runWithFirmContext() wrap, keyed on
 * the firm already known at each call site — never a nested self-wrap
 * inside an already-active outer context.
 */
class MigrationProjectService
{
    public function create(
        Firm $firm,
        MigrationSourceType $sourceType,
        ?string $notes = null,
        ?FirmUser $createdByFirmUser = null,
        ?PlatformAdmin $createdByPlatformAdmin = null,
    ): MigrationProject {
        return (new TenantContextService)->runWithFirmContext($firm, fn () => MigrationProject::create([
            'firm_id' => $firm->id,
            'source_type' => $sourceType,
            'status' => MigrationProjectStatus::Draft,
            'notes' => $notes,
            'created_by_firm_user_id' => $createdByFirmUser?->id,
            'created_by_platform_admin_id' => $createdByPlatformAdmin?->id,
        ]));
    }

    public function start(MigrationProject $project): MigrationProject
    {
        return (new TenantContextService)->runWithFirmContext($project->firm_id, function () use ($project) {
            $project->update(['status' => MigrationProjectStatus::InProgress, 'started_at' => now()]);

            return $project->fresh();
        });
    }

    public function complete(MigrationProject $project): MigrationProject
    {
        return (new TenantContextService)->runWithFirmContext($project->firm_id, function () use ($project) {
            $project->update(['status' => MigrationProjectStatus::Completed, 'completed_at' => now()]);

            return $project->fresh();
        });
    }

    public function cancel(MigrationProject $project): MigrationProject
    {
        return (new TenantContextService)->runWithFirmContext($project->firm_id, function () use ($project) {
            $project->update(['status' => MigrationProjectStatus::Cancelled]);

            return $project->fresh();
        });
    }

    public function fail(MigrationProject $project): MigrationProject
    {
        return (new TenantContextService)->runWithFirmContext($project->firm_id, function () use ($project) {
            $project->update(['status' => MigrationProjectStatus::Failed]);

            return $project->fresh();
        });
    }
}
