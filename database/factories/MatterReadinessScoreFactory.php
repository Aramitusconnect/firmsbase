<?php

namespace Database\Factories;

use App\Enums\MatterReadinessStatus;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterReadinessScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterReadinessScore>
 */
class MatterReadinessScoreFactory extends Factory
{
    protected $model = MatterReadinessScore::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => Matter::factory(),
            'status' => MatterReadinessStatus::NotReady,
            'satisfied_count' => 0,
            'total_count' => 0,
            'breakdown_json' => [],
            'computed_at' => null,
        ];
    }

    public function forMatter(Matter $matter): static
    {
        return $this->state(fn () => ['firm_id' => $matter->firm_id, 'matter_id' => $matter->id]);
    }
}
