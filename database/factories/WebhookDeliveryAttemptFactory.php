<?php

namespace Database\Factories;

use App\Enums\WebhookDeliveryAttemptOutcome;
use App\Models\Firm;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDeliveryAttempt>
 */
class WebhookDeliveryAttemptFactory extends Factory
{
    protected $model = WebhookDeliveryAttempt::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'webhook_delivery_id' => WebhookDelivery::factory(),
            'webhook_secret_id' => null,
            'attempt_number' => 1,
            'outcome' => WebhookDeliveryAttemptOutcome::Success,
            'http_status_code' => 200,
            'response_snippet' => 'ok',
            'attempted_at' => now(),
        ];
    }

    public function forDelivery(WebhookDelivery $delivery): static
    {
        return $this->state(fn () => [
            'firm_id' => $delivery->firm_id,
            'webhook_delivery_id' => $delivery->id,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'outcome' => WebhookDeliveryAttemptOutcome::Failure,
            'http_status_code' => 500,
            'response_snippet' => 'internal server error',
        ]);
    }
}
