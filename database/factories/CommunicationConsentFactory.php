<?php

namespace Database\Factories;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\CommunicationConsent;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunicationConsent>
 */
class CommunicationConsentFactory extends Factory
{
    protected $model = CommunicationConsent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'client_id' => $this->faker->numberBetween(1, 100000),
            'channel' => ConsentChannel::Email,
            'status' => ConsentStatus::Granted,
            'consent_text_version' => 'v1',
            'granted_at' => now(),
            'revoked_at' => null,
            'expires_at' => null,
            'captured_via' => 'web_form',
            'captured_ip' => $this->faker->ipv4(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function channel(ConsentChannel $channel): static
    {
        return $this->state(fn () => ['channel' => $channel]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => ConsentStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => ConsentStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }
}
