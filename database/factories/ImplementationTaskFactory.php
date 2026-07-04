<?php

namespace Database\Factories;

use App\Enums\ImplementationTaskStatus;
use App\Models\ImplementationProject;
use App\Models\ImplementationTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImplementationTask>
 */
class ImplementationTaskFactory extends Factory
{
    protected $model = ImplementationTask::class;

    public function definition(): array
    {
        return [
            'implementation_project_id' => ImplementationProject::factory(),
            'task_key' => $this->faker->unique()->randomElement(ImplementationTask::TASK_KEYS),
            'status' => ImplementationTaskStatus::Pending->value,
            'is_required' => true,
        ];
    }

    public function forProject(ImplementationProject $project): static
    {
        return $this->state(fn () => ['implementation_project_id' => $project->id]);
    }

    public function key(string $taskKey): static
    {
        return $this->state(fn () => ['task_key' => $taskKey]);
    }
}
