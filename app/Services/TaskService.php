<?php

namespace App\Services;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;

/**
 * TaskService — task lifecycle (create/assign/complete/cancel) and
 * Overdue derivation. TaskDependencyService owns the Blocked
 * transition exclusively — this service never sets or clears Blocked
 * directly (PDF: "overdue is derived from due_at and status, not
 * manually trusted"; the same discipline applies to Blocked).
 */
class TaskService
{
    public function create(
        Firm $firm,
        string $title,
        ?Matter $matter = null,
        ?Client $client = null,
        ?User $assignedTo = null,
        TaskPriority $priority = TaskPriority::Normal,
        ?\DateTimeInterface $dueAt = null,
        ?string $description = null,
        ?User $createdBy = null,
    ): Task {
        return Task::create([
            'firm_id' => $firm->id,
            'matter_id' => $matter?->id,
            'client_id' => $client?->id,
            'assigned_to' => $assignedTo?->id,
            'title' => $title,
            'description' => $description,
            'status' => TaskStatus::Open,
            'priority' => $priority,
            'due_at' => $dueAt,
            'created_by' => $createdBy?->id,
        ]);
    }

    public function assign(Task $task, User $assignee): Task
    {
        $task->update(['assigned_to' => $assignee->id]);

        return $task->fresh();
    }

    public function start(Task $task): Task
    {
        if ($task->status !== TaskStatus::Open) {
            throw new \RuntimeException('Only an open task can be started.');
        }

        $task->update(['status' => TaskStatus::InProgress]);

        return $task->fresh();
    }

    public function complete(Task $task): Task
    {
        if ($task->status === TaskStatus::Blocked) {
            throw new \RuntimeException('A blocked task cannot be completed until its dependencies are resolved.');
        }

        $task->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);

        return $task->fresh();
    }

    public function cancel(Task $task): Task
    {
        $task->update(['status' => TaskStatus::Cancelled, 'cancelled_at' => now()]);

        return $task->fresh();
    }

    /**
     * Derives Overdue from due_at rather than accepting it as a
     * directly-settable value. Idempotent and safe to call repeatedly
     * (e.g. from a future scheduled command) — never overrides a
     * terminal status.
     */
    public function refreshOverdueStatus(Task $task): Task
    {
        if (! in_array($task->status, [TaskStatus::Open, TaskStatus::InProgress], true)) {
            return $task;
        }

        if ($task->due_at && $task->due_at->isPast()) {
            $task->update(['status' => TaskStatus::Overdue]);
        }

        return $task->fresh();
    }
}
