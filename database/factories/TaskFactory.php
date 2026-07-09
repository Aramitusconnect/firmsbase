<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Firm;
use App\Models\Task;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);
        $models = $results instanceof Model ? new Collection([$results]) : $results;
        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => null,
            'client_id' => null,
            'assigned_to' => null,
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->optional()->paragraph(),
            'status' => TaskStatus::Open,
            'priority' => TaskPriority::Normal,
            'due_at' => now()->addDays(7),
            'completed_at' => null,
            'cancelled_at' => null,
            'created_by' => null,
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn () => ['due_at' => now()->subDays(3)]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::Completed, 'completed_at' => now()]);
    }
}
