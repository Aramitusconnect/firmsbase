<?php

namespace Database\Factories;

use App\Enums\DocumentChaseRuleStatus;
use App\Models\DocumentChaseRule;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentChaseRule>
 */
class DocumentChaseRuleFactory extends Factory
{
    protected $model = DocumentChaseRule::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'name' => 'Default reminder cadence',
            'status' => DocumentChaseRuleStatus::Active,
            'applies_to' => null,
            'reminder_offsets_days' => [7, 3, 1],
            'max_reminders' => 3,
            'escalate_after_days' => 14,
            'escalate_to_user_id' => null,
            'channel' => 'email',
            'created_by' => null,
        ];
    }

    public function paused(): static
    {
        return $this->state(fn () => ['status' => DocumentChaseRuleStatus::Paused]);
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
