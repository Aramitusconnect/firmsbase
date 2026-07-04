<?php

namespace Database\Factories;

use App\Enums\CalendarEventType;
use App\Models\CalendarEvent;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    protected $model = CalendarEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => null,
            'event_type' => CalendarEventType::Standalone,
            'subject_type' => null,
            'subject_id' => null,
            'title' => $this->faker->sentence(3),
            'starts_at' => now()->addDays(2),
            'ends_at' => null,
            'all_day' => false,
            'created_by' => null,
        ];
    }

    public function forSubject(string $subjectType, int $subjectId, CalendarEventType $type): static
    {
        return $this->state(fn () => [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event_type' => $type,
        ]);
    }
}
