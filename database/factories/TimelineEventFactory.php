<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\TimelineEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimelineEvent>
 */
class TimelineEventFactory extends Factory
{
    protected $model = TimelineEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'subject_type' => null,
            'subject_id' => null,
            'event_type' => 'lead_created',
            'actor_type' => null,
            'actor_id' => null,
            'occurred_at' => now(),
            'metadata_json' => [],
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function eventType(string $type): static
    {
        return $this->state(fn () => ['event_type' => $type]);
    }

    public function forSubject(\Illuminate\Database\Eloquent\Model $subject): static
    {
        return $this->state(fn () => [
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
        ]);
    }
}
