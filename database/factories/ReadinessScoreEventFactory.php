<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\Matter;
use App\Models\ReadinessScoreEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadinessScoreEvent>
 */
class ReadinessScoreEventFactory extends Factory
{
    protected $model = ReadinessScoreEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => Matter::factory(),
            'event_type' => 'recomputed',
            'previous_status' => null,
            'new_status' => 'not_ready',
            'metadata_json' => [],
        ];
    }

    public function forMatter(Matter $matter): static
    {
        return $this->state(fn () => ['firm_id' => $matter->firm_id, 'matter_id' => $matter->id]);
    }
}
