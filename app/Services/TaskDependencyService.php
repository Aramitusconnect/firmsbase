<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Exceptions\TenantIsolationException;
use App\Models\Task;
use App\Models\TaskDependency;
use Illuminate\Support\Facades\DB;

/**
 * TaskDependencyService — the ONLY place task_dependencies rows are
 * created. Rejects dependency cycles at write time (project rule) via
 * a graph traversal BEFORE inserting: adding "task depends on
 * blockedByTask" is refused if blockedByTask already (transitively)
 * depends on task. Also refuses the trivial task_id == blocked_by_
 * task_id case (backed by a database CHECK constraint too, defense in
 * depth). Recomputes the dependent task's Blocked status immediately
 * after any add/remove.
 */
class TaskDependencyService
{
    public function addDependency(Task $task, Task $blockedByTask): TaskDependency
    {
        if ($task->id === $blockedByTask->id) {
            throw new \InvalidArgumentException('A task cannot depend on itself.');
        }

        $this->assertSameFirm($task, $blockedByTask);

        if ($this->wouldCreateCycle($task, $blockedByTask)) {
            throw new \RuntimeException(
                "Adding this dependency would create a cycle: task #{$blockedByTask->id} already (transitively) depends on task #{$task->id}."
            );
        }

        return (new TenantContextService)->runWithFirmContext($task->firm_id, function () use ($task, $blockedByTask) {
            return DB::transaction(function () use ($task, $blockedByTask) {
                $dependency = TaskDependency::firstOrCreate([
                    'task_id' => $task->id,
                    'blocked_by_task_id' => $blockedByTask->id,
                ]);

                $this->refreshBlockedStatus($task);

                return $dependency;
            });
        });
    }

    public function removeDependency(Task $task, Task $blockedByTask): void
    {
        (new TenantContextService)->runWithFirmContext($task->firm_id, function () use ($task, $blockedByTask) {
            TaskDependency::query()
                ->where('task_id', $task->id)
                ->where('blocked_by_task_id', $blockedByTask->id)
                ->delete();

            $this->refreshBlockedStatus($task->fresh());
        });
    }

    /**
     * Defense-in-depth tenant-isolation guard, following the same
     * pattern as TenantSafeTrustPolicyService::assertMatterMatchesLedger().
     * This service is the ONLY place task_dependencies rows are created
     * (see class docblock), so it must not itself trust callers to have
     * already verified both tasks belong to the same firm — the one
     * existing Filament caller does check this, but the service has no
     * defense-in-depth of its own without this assertion.
     */
    private function assertSameFirm(Task $task, Task $blockedByTask): void
    {
        if ($task->firm_id !== $blockedByTask->firm_id) {
            throw new TenantIsolationException(
                "Task [id={$task->id}] and Task [id={$blockedByTask->id}] do not belong to the same firm."
            );
        }
    }

    /**
     * True when $blockedByTask already depends (directly or
     * transitively) on $task — i.e. a path already exists from
     * blockedByTask back to task through the blocked_by_task_id graph,
     * which adding task -> blockedByTask would close into a cycle.
     */
    private function wouldCreateCycle(Task $task, Task $blockedByTask): bool
    {
        $visited = [];
        $queue = [$blockedByTask->id];

        while (! empty($queue)) {
            $currentId = array_shift($queue);

            if ($currentId === $task->id) {
                return true;
            }

            if (isset($visited[$currentId])) {
                continue;
            }

            $visited[$currentId] = true;

            $nextIds = TaskDependency::query()
                ->where('task_id', $currentId)
                ->pluck('blocked_by_task_id')
                ->all();

            array_push($queue, ...$nextIds);
        }

        return false;
    }

    /**
     * A task is Blocked exactly when it has at least one dependency
     * whose own status is not Completed/Cancelled. Never overrides a
     * terminal status (Completed/Cancelled).
     */
    public function refreshBlockedStatus(Task $task): Task
    {
        if (in_array($task->status, [TaskStatus::Completed, TaskStatus::Cancelled], true)) {
            return $task;
        }

        return (new TenantContextService)->runWithFirmContext($task->firm_id, function () use ($task) {
            $hasUnresolvedDependency = $task->dependencies()
                ->whereHas('blockedByTask', fn ($q) => $q->whereNotIn('status', [
                    TaskStatus::Completed->value,
                    TaskStatus::Cancelled->value,
                ]))
                ->exists();

            if ($hasUnresolvedDependency) {
                $task->update(['status' => TaskStatus::Blocked]);
            } elseif ($task->status === TaskStatus::Blocked) {
                $task->update(['status' => TaskStatus::Open]);
            }

            return $task->fresh();
        });
    }

    public function isBlocked(Task $task): bool
    {
        return $task->status === TaskStatus::Blocked;
    }
}
