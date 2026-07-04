<?php

namespace Database\Factories;

use App\Models\ActivationChecklist;
use App\Models\ActivationChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivationChecklistItem>
 */
class ActivationChecklistItemFactory extends Factory
{
    protected $model = ActivationChecklistItem::class;

    public function definition(): array
    {
        return [
            'activation_checklist_id' => ActivationChecklist::factory(),
            'item_key' => $this->faker->unique()->slug(2, false),
            'label' => $this->faker->sentence(4),
            'is_required' => true,
            'is_complete' => false,
            'completed_by' => null,
            'completed_at' => null,
            'waived_at' => null,
            'waived_by' => null,
            'waiver_reason' => null,
        ];
    }

    public function forChecklist(ActivationChecklist $checklist): static
    {
        return $this->state(fn () => ['activation_checklist_id' => $checklist->id]);
    }

    public function optional(): static
    {
        return $this->state(fn () => ['is_required' => false]);
    }

    public function complete(): static
    {
        return $this->state(fn () => [
            'is_complete' => true,
            'completed_at' => now(),
        ]);
    }

    public function waived(string $reason = 'Not applicable'): static
    {
        return $this->state(fn () => [
            'waived_at' => now(),
            'waiver_reason' => $reason,
        ]);
    }
}
