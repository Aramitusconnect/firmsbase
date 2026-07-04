<?php

namespace App\Services;

use App\Enums\ImplementationProjectStatus;
use App\Enums\ImplementationTaskStatus;
use App\Models\ImplementationProject;
use App\Models\ImplementationTask;
use App\Models\PlatformAdmin;

/**
 * ImplementationTaskService — completing/skipping a task always
 * recomputes the parent project's status via updateProjectProgress(),
 * mirroring how MatterReadinessScore/TaskDependencyService derive
 * status rather than accept it as a directly-settable value.
 */
class ImplementationTaskService
{
    public function complete(ImplementationTask $task, PlatformAdmin $completedBy): ImplementationTask
    {
        $task->update([
            'status' => ImplementationTaskStatus::Completed,
            'completed_by' => $completedBy->id,
            'completed_at' => now(),
        ]);

        $this->updateProjectProgress($task->implementationProject);

        return $task->fresh();
    }

    public function skip(ImplementationTask $task): ImplementationTask
    {
        $task->update(['status' => ImplementationTaskStatus::Skipped]);

        $this->updateProjectProgress($task->implementationProject);

        return $task->fresh();
    }

    public function block(ImplementationTask $task): ImplementationTask
    {
        $task->update(['status' => ImplementationTaskStatus::Blocked]);

        $this->updateProjectProgress($task->implementationProject);

        return $task->fresh();
    }

    public function updateProjectProgress(ImplementationProject $project): ImplementationProject
    {
        $tasks = $project->tasks()->get();

        $requiredTasks = $tasks->where('is_required', true);
        $requiredDone = $requiredTasks->whereIn('status', [
            ImplementationTaskStatus::Completed,
            ImplementationTaskStatus::Skipped,
        ]);

        $anyBlocked = $tasks->contains(fn (ImplementationTask $task) => $task->status === ImplementationTaskStatus::Blocked);
        $anyStarted = $tasks->contains(fn (ImplementationTask $task) => $task->status !== ImplementationTaskStatus::Pending);

        $status = match (true) {
            $requiredTasks->count() > 0 && $requiredDone->count() === $requiredTasks->count() => ImplementationProjectStatus::Completed,
            $anyBlocked => ImplementationProjectStatus::Blocked,
            $anyStarted => ImplementationProjectStatus::InProgress,
            default => ImplementationProjectStatus::NotStarted,
        };

        $project->update([
            'status' => $status,
            'completed_at' => $status === ImplementationProjectStatus::Completed ? ($project->completed_at ?? now()) : $project->completed_at,
        ]);

        return $project->fresh();
    }
}
