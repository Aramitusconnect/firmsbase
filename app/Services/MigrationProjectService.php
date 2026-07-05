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
        return MigrationProject::create([
            'firm_id' => $firm->id,
            'source_type' => $sourceType,
            'status' => MigrationProjectStatus::Draft,
            'notes' => $notes,
            'created_by_firm_user_id' => $createdByFirmUser?->id,
            'created_by_platform_admin_id' => $createdByPlatformAdmin?->id,
        ]);
    }

    public function start(MigrationProject $project): MigrationProject
    {
        $project->update(['status' => MigrationProjectStatus::InProgress, 'started_at' => now()]);

        return $project->fresh();
    }

    public function complete(MigrationProject $project): MigrationProject
    {
        $project->update(['status' => MigrationProjectStatus::Completed, 'completed_at' => now()]);

        return $project->fresh();
    }

    public function cancel(MigrationProject $project): MigrationProject
    {
        $project->update(['status' => MigrationProjectStatus::Cancelled]);

        return $project->fresh();
    }

    public function fail(MigrationProject $project): MigrationProject
    {
        $project->update(['status' => MigrationProjectStatus::Failed]);

        return $project->fresh();
    }
}
