<?php

namespace Database\Factories;

use App\Enums\ConflictCheckResultStatus;
use App\Models\ConflictCheckResult;
use App\Models\ConflictCheckRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConflictCheckResult>
 */
class ConflictCheckResultFactory extends Factory
{
    protected $model = ConflictCheckResult::class;

    public function definition(): array
    {
        return [
            'conflict_check_run_id' => ConflictCheckRun::factory(),
            'matched_type' => 'party',
            'matched_id' => $this->faker->numberBetween(1, 100000),
            'matched_value' => $this->faker->name(),
            'status' => ConflictCheckResultStatus::PossibleMatch,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
        ];
    }

    public function forRun(ConflictCheckRun $run): static
    {
        return $this->state(fn () => ['conflict_check_run_id' => $run->id]);
    }

    public function freeText(string $name): static
    {
        return $this->state(fn () => [
            'matched_type' => 'free_text',
            'matched_id' => null,
            'matched_value' => $name,
        ]);
    }

    public function confirmedConflict(): static
    {
        return $this->state(fn () => ['status' => ConflictCheckResultStatus::ConfirmedConflict]);
    }

    public function dismissed(): static
    {
        return $this->state(fn () => ['status' => ConflictCheckResultStatus::Dismissed]);
    }
}
