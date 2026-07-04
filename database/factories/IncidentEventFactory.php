<?php

namespace Database\Factories;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\IncidentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IncidentEvent>
 */
class IncidentEventFactory extends Factory
{
    protected $model = IncidentEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => null,
            'correlation_id' => (string) Str::uuid(),
            'event_type' => 'opened',
            'severity' => IncidentSeverity::Medium,
            'status' => IncidentStatus::Investigating,
            'customer_impact' => false,
            'notification_needed' => false,
            'root_cause' => null,
            'resolution' => null,
            'message' => 'Simulated incident.',
            'actor_user_id' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['event_type' => 'resolved', 'status' => IncidentStatus::Resolved, 'resolution' => 'Fixed.']);
    }
}
