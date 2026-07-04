<?php

namespace Database\Factories;

use App\Enums\StatusPageEventStatus;
use App\Models\StatusPageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StatusPageEvent>
 */
class StatusPageEventFactory extends Factory
{
    protected $model = StatusPageEvent::class;

    public function definition(): array
    {
        return [
            'correlation_id' => (string) Str::uuid(),
            'incident_correlation_id' => null,
            'event_type' => 'investigating',
            'status' => StatusPageEventStatus::Published,
            'component_affected' => 'client_portal',
            'public_message' => 'We are investigating an issue.',
            'starts_at' => now(),
            'resolved_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['event_type' => 'resolved', 'resolved_at' => now()]);
    }
}
