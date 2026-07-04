<?php

namespace Database\Factories;

use App\Enums\DeadlineStatus;
use App\Models\Deadline;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deadline>
 */
class DeadlineFactory extends Factory
{
    protected $model = Deadline::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => null,
            'title' => $this->faker->sentence(3),
            'deadline_type' => 'filing_deadline',
            'due_at' => now()->addDays(30),
            'jurisdiction' => 'USCIS',
            'source' => 'court_order',
            'reminder_offsets_days' => [7, 3, 1],
            'status' => DeadlineStatus::Upcoming,
            'completed_at' => null,
            'cancelled_at' => null,
            'created_by' => null,
        ];
    }

    public function missed(): static
    {
        return $this->state(fn () => ['status' => DeadlineStatus::Missed, 'due_at' => now()->subDays(2)]);
    }
}
