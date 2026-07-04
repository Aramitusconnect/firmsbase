<?php

namespace Database\Factories;

use App\Enums\PlatformSalesTaskStatus;
use App\Models\PlatformLead;
use App\Models\PlatformSalesTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformSalesTask>
 */
class PlatformSalesTaskFactory extends Factory
{
    protected $model = PlatformSalesTask::class;

    public function definition(): array
    {
        return [
            'taskable_type' => PlatformLead::class,
            'taskable_id' => PlatformLead::factory(),
            'title' => $this->faker->sentence(4),
            'status' => PlatformSalesTaskStatus::Open->value,
            'due_at' => now()->addDays(2),
        ];
    }

    public function forTaskable(\Illuminate\Database\Eloquent\Model $taskable): static
    {
        return $this->state(fn () => [
            'taskable_type' => $taskable::class,
            'taskable_id' => $taskable->id,
        ]);
    }
}
