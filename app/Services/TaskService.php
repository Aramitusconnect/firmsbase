<?php

namespace App\Services;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WebhookEventType;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TaskService — task lifecycle (create/assign/complete/cancel) and
 * Overdue derivation. TaskDependencyService owns the Blocked
 * transition exclusively — this service never sets or clears Blocked
 * directly (PDF: "overdue is derived from due_at and status, not
 * manually trusted"; the same discipline applies to Blocked).
 *
 * Phase 14b addition: complete() fires task.completed exactly once,
 * only when the status update below actually succeeds (the guard
 * above throws for a Blocked task before any write happens, so a
 * disallowed transition can never fire the event). Not wrapped in an
 * explicit DB::transaction() — the single update() call is already a
 * durable write by the time DB::afterCommit()'s closure is registered,
 * so it runs immediately.
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
        return (new TenantContextService())->runWithFirmContext($firm, fn () => Task::create([
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
        ]));
    }

    public function assign(Task $task, User $assignee): Task
    {
        return (new TenantContextService())->runWithFirmContext($task->firm_id, function () use ($task, $assignee) {
            $task->update(['assigned_to' => $assignee->id]);

            return $task->fresh();
        });
    }

    public function start(Task $task): Task
    {
        if ($task->status !== TaskStatus::Open) {
            throw new \RuntimeException('Only an open task can be started.');
        }

        return (new TenantContextService())->runWithFirmContext($task->firm_id, function () use ($task) {
            $task->update(['status' => TaskStatus::InProgress]);

            return $task->fresh();
        });
    }

    public function complete(Task $task): Task
    {
        if ($task->status === TaskStatus::Blocked) {
            throw new \RuntimeException('A blocked task cannot be completed until its dependencies are resolved.');
        }

        $task = (new TenantContextService())->runWithFirmContext($task->firm_id, function () use ($task) {
            $task->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);

            return $task->fresh();
        });

        DB::afterCommit(function () use ($task) {
            try {
                app(WebhookEventRecorderService::class)->record($task->firm, WebhookEventType::TaskCompleted, $task);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $task;
    }

    public function cancel(Task $task): Task
    {
        return (new TenantContextService())->runWithFirmContext($task->firm_id, function () use ($task) {
            $task->update(['status' => TaskStatus::Cancelled, 'cancelled_at' => now()]);

            return $task->fresh();
        });
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

        return (new TenantContextService())->runWithFirmContext($task->firm_id, function () use ($task) {
            if ($task->due_at && $task->due_at->isPast()) {
                $task->update(['status' => TaskStatus::Overdue]);
            }

            return $task->fresh();
        });
    }
}
