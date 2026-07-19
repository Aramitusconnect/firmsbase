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
 *
 * implementation_projects carries FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_08_29_970004_prepare_row_level_security_and_force_rls_on_implementation_projects_table.php).
 * implementation_tasks itself carries no firm_id column (only
 * implementation_project_id) — there is no in-memory value this
 * service could key a tenant-context wrap on before a lazy
 * $task->implementationProject relation load resolves, and a general
 * "look up any implementation_project_id's firm_id" RLS policy clause
 * would be a real information-leak widening (only a "your own row by
 * your own identity" self-lookup clause is safe, not a general lookup).
 * REQUIRED fix (per 3 rounds of security review, no ambient-context
 * fallback permitted): complete()/skip()/block() now take the
 * already-known ImplementationProject as an explicit parameter instead
 * of relying on the lazy relation. Every current call site
 * (tests/Feature/Implementation/ImplementationTaskServiceTest.php:33,45,57)
 * already holds the project in scope before calling these methods —
 * updating those call sites for the new signature is a separate,
 * test-focused phase's job, flagged and not performed here.
 */
class ImplementationTaskService
{
    public function complete(ImplementationTask $task, ImplementationProject $project, PlatformAdmin $completedBy): ImplementationTask
    {
        $task->update([
            'status' => ImplementationTaskStatus::Completed,
            'completed_by' => $completedBy->id,
            'completed_at' => now(),
        ]);

        $this->updateProjectProgress($project);

        return $task->fresh();
    }

    public function skip(ImplementationTask $task, ImplementationProject $project): ImplementationTask
    {
        $task->update(['status' => ImplementationTaskStatus::Skipped]);

        $this->updateProjectProgress($project);

        return $task->fresh();
    }

    public function block(ImplementationTask $task, ImplementationProject $project): ImplementationTask
    {
        $task->update(['status' => ImplementationTaskStatus::Blocked]);

        $this->updateProjectProgress($project);

        return $task->fresh();
    }

    /**
     * $project is already a hydrated parameter here (firm_id already
     * in memory), so this method is safely wrappable on its own — the
     * write below is wrapped in a runWithFirmContext() call keyed on
     * $project->firm_id, at the whole-call granularity established by
     * this rollout's convention.
     */
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

        return (new TenantContextService())->runWithFirmContext($project->firm_id, function () use ($project, $status) {
            $project->update([
                'status' => $status,
                'completed_at' => $status === ImplementationProjectStatus::Completed ? ($project->completed_at ?? now()) : $project->completed_at,
            ]);

            return $project->fresh();
        });
    }
}
