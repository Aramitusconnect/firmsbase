<?php

namespace Database\Factories;

use App\Enums\ActivationChecklistStatus;
use App\Models\ActivationChecklist;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivationChecklist>
 */
class ActivationChecklistFactory extends Factory
{
    protected $model = ActivationChecklist::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'status' => ActivationChecklistStatus::InProgress,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ActivationChecklistStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
