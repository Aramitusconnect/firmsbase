<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskDependency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskDependency>
 */
class TaskDependencyFactory extends Factory
{
    protected $model = TaskDependency::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'blocked_by_task_id' => Task::factory(),
        ];
    }

    public function between(Task $task, Task $blockedByTask): static
    {
        return $this->state(fn () => ['task_id' => $task->id, 'blocked_by_task_id' => $blockedByTask->id]);
    }
}
