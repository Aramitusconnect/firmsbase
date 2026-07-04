<?php

namespace Database\Factories;

use App\Enums\ConsentChannel;
use App\Enums\NotificationEventStatus;
use App\Models\Firm;
use App\Models\NotificationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationEvent>
 */
class NotificationEventFactory extends Factory
{
    protected $model = NotificationEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'notification_template_id' => null,
            'client_id' => null,
            'matter_id' => null,
            'correlation_id' => (string) Str::uuid(),
            'channel' => ConsentChannel::Email,
            'recipient' => $this->faker->safeEmail(),
            'status' => NotificationEventStatus::Attempted,
            'reason' => null,
            'subject_type' => null,
            'subject_id' => null,
        ];
    }

    public function blocked(string $reason): static
    {
        return $this->state(fn () => ['status' => NotificationEventStatus::Blocked, 'reason' => $reason]);
    }
}
