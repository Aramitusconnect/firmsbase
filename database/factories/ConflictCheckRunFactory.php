<?php

namespace Database\Factories;

use App\Enums\ConflictCheckRunStatus;
use App\Enums\ConflictCheckScope;
use App\Models\ConflictCheckRun;
use App\Models\Firm;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConflictCheckRun>
 */
class ConflictCheckRunFactory extends Factory
{
    protected $model = ConflictCheckRun::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => Matter::factory(),
            'requested_by' => null,
            'status' => ConflictCheckRunStatus::Pending,
            'scope' => ConflictCheckScope::Firm,
            'searched_terms_json' => [],
            'result_count' => 0,
            'completed_at' => null,
        ];
    }

    public function forMatter(Matter $matter): static
    {
        return $this->state(fn () => ['firm_id' => $matter->firm_id, 'matter_id' => $matter->id]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ConflictCheckRunStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
