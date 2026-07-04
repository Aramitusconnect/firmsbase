<?php

namespace App\Services;

use App\Enums\ImplementationProjectStatus;
use App\Enums\ImplementationTaskStatus;
use App\Models\Firm;
use App\Models\ImplementationProject;
use App\Models\ImplementationTask;
use App\Models\PlatformAdmin;

/**
 * ImplementationProjectService — the only writer of
 * implementation_projects. Creating a project always creates the full
 * standard set of ImplementationTask::TASK_KEYS rows (kickoff through
 * success_review_30_day), mirroring ActivationChecklistService's own
 * "creating a checklist creates its items" pattern from Phase 1.
 */
class ImplementationProjectService
{
    public function createForFirm(Firm $firm, ?PlatformAdmin $assignedTo = null): ImplementationProject
    {
        $project = ImplementationProject::create([
            'firm_id' => $firm->id,
            'assigned_to' => $assignedTo?->id,
            'status' => ImplementationProjectStatus::NotStarted,
        ]);

        foreach (ImplementationTask::TASK_KEYS as $taskKey) {
            ImplementationTask::create([
                'implementation_project_id' => $project->id,
                'task_key' => $taskKey,
                'status' => ImplementationTaskStatus::Pending,
                'is_required' => true,
            ]);
        }

        return $project->fresh('tasks');
    }

    public function start(ImplementationProject $project): ImplementationProject
    {
        $project->update([
            'status' => ImplementationProjectStatus::InProgress,
            'started_at' => $project->started_at ?? now(),
        ]);

        return $project->fresh();
    }

    public function markGoLive(ImplementationProject $project): ImplementationProject
    {
        $project->update([
            'go_live_at' => now(),
            'success_review_due_at' => now()->addDays(30),
        ]);

        return $project->fresh();
    }

    public function completeSuccessReview(ImplementationProject $project): ImplementationProject
    {
        $project->update([
            'success_review_completed_at' => now(),
            'status' => ImplementationProjectStatus::Completed,
            'completed_at' => now(),
        ]);

        return $project->fresh();
    }
}
