<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\SecurityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityEvent>
 */
class SecurityEventFactory extends Factory
{
    protected $model = SecurityEvent::class;

    public function definition(): array
    {
        return [
            // Nullable by default — platform-level events are legitimate.
            'firm_id' => null,
            'actor_type' => 'User',
            'actor_id' => null,
            'event_type' => 'login_succeeded',
            'category' => 'authentication',
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'metadata' => [],
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
