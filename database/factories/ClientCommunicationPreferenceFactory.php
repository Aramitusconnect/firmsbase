<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientCommunicationPreference>
 */
class ClientCommunicationPreferenceFactory extends Factory
{
    protected $model = ClientCommunicationPreference::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'client_id' => null,
            'preferred_language' => 'en',
            'preferred_timezone' => 'America/New_York',
            'notification_frequency' => 'immediate',
            'do_not_contact' => false,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
        ]);
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => [
            'firm_id' => $client->firm_id,
            'client_id' => $client->id,
        ]);
    }

    public function withClient(): static
    {
        return $this->state(function () {
            $client = Client::factory()->create();

            return [
                'firm_id' => $client->firm_id,
                'client_id' => $client->id,
            ];
        });
    }

    public function doNotContact(): static
    {
        return $this->state(fn () => [
            'do_not_contact' => true,
        ]);
    }
}
