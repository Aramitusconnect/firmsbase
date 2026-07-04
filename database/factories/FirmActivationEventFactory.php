<?php

namespace Database\Factories;

use App\Enums\FirmActivationEventStatus;
use App\Models\Firm;
use App\Models\FirmActivationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FirmActivationEvent>
 */
class FirmActivationEventFactory extends Factory
{
    protected $model = FirmActivationEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'event_type' => 'checklist_item_completed',
            'status' => FirmActivationEventStatus::Completed,
            'checklist_item_key' => null,
            'blocking_reason' => null,
            'actor_user_id' => null,
            'metadata_json' => [],
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function blocked(string $reason): static
    {
        return $this->state(fn () => ['status' => FirmActivationEventStatus::Blocked, 'blocking_reason' => $reason]);
    }
}
