<?php

namespace Database\Factories;

use App\Enums\ClientPortalStatus;
use App\Models\Client;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'communication_preferences_id' => null,
            'display_name' => $this->faker->name(),
            'legal_name' => null,
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'preferred_language' => 'en',
            'preferred_timezone' => 'America/New_York',
            'portal_status' => ClientPortalStatus::NotInvited,
            'portal_invitation_token' => null,
            'portal_invitation_sent_at' => null,
            'portal_invitation_accepted_at' => null,
            'created_by' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
