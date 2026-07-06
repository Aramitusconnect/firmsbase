<?php

namespace Database\Factories;

use App\Enums\AiToolActionStatus;
use App\Models\AiToolAction;
use App\Models\AiUsageEvent;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiToolAction>
 */
class AiToolActionFactory extends Factory
{
    protected $model = AiToolAction::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => null,
            'ai_usage_event_id' => AiUsageEvent::factory(),
            'tool_name' => 'draft_summary_tool',
            'input_snapshot_json' => ['note' => 'fixture input'],
            'output_snapshot_json' => ['note' => 'fixture output'],
            'was_constrained' => false,
            'status' => AiToolActionStatus::Executed,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => [
            'was_constrained' => true,
            'status' => AiToolActionStatus::Blocked,
        ]);
    }
}
