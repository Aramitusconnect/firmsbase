<?php

namespace Database\Factories;

use App\Enums\PilotFeedbackCategory;
use App\Enums\PilotFeedbackPriority;
use App\Enums\PilotFeedbackSource;
use App\Enums\PilotFeedbackStatus;
use App\Models\Firm;
use App\Models\PilotFeedbackItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PilotFeedbackItem>
 */
class PilotFeedbackItemFactory extends Factory
{
    protected $model = PilotFeedbackItem::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'client_id' => null,
            'matter_id' => null,
            'user_id' => null,
            'source' => PilotFeedbackSource::Firm,
            'category' => PilotFeedbackCategory::UsabilityIssue,
            'priority' => PilotFeedbackPriority::Medium,
            'status' => PilotFeedbackStatus::New,
            'title' => 'Sample pilot feedback',
            'description' => 'The document upload button was hard to find.',
            'resolution_notes' => null,
            'follow_up_required' => false,
            'follow_up_at' => null,
            'resolved_at' => null,
            'created_by' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function internal(): static
    {
        return $this->state(fn () => ['firm_id' => null, 'source' => PilotFeedbackSource::Internal]);
    }
}
