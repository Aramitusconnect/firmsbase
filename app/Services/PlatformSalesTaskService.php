<?php

namespace App\Services;

use App\Enums\PlatformSalesTaskStatus;
use App\Models\PlatformAdmin;
use App\Models\PlatformSalesTask;
use Illuminate\Database\Eloquent\Model;

/**
 * PlatformSalesTaskService — the only writer of platform_sales_tasks.
 * Deliberately unrelated to Phase 4's TaskService/tasks table — a
 * platform sales follow-up task is not a firm/matter/client legal
 * workflow task.
 */
class PlatformSalesTaskService
{
    public function create(
        Model $taskable,
        string $title,
        ?PlatformAdmin $assignedTo = null,
        ?PlatformAdmin $createdBy = null,
        ?\DateTimeInterface $dueAt = null,
    ): PlatformSalesTask {
        return PlatformSalesTask::create([
            'taskable_type' => $taskable::class,
            'taskable_id' => $taskable->id,
            'assigned_to' => $assignedTo?->id,
            'created_by' => $createdBy?->id,
            'title' => $title,
            'status' => PlatformSalesTaskStatus::Open,
            'due_at' => $dueAt,
        ]);
    }

    public function complete(PlatformSalesTask $task): PlatformSalesTask
    {
        $task->update([
            'status' => PlatformSalesTaskStatus::Completed,
            'completed_at' => now(),
        ]);

        return $task->fresh();
    }

    public function cancel(PlatformSalesTask $task): PlatformSalesTask
    {
        $task->update(['status' => PlatformSalesTaskStatus::Cancelled]);

        return $task->fresh();
    }
}
