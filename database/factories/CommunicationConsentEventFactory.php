<?php

namespace Database\Factories;

use App\Models\CommunicationConsent;
use App\Models\CommunicationConsentEvent;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunicationConsentEvent>
 */
class CommunicationConsentEventFactory extends Factory
{
    protected $model = CommunicationConsentEvent::class;

    public function definition(): array
    {
        return [
            'communication_consent_id' => CommunicationConsent::factory(),
            'firm_id' => Firm::factory(),
            'action' => 'captured',
            'previous_status' => null,
            'new_status' => 'granted',
            'consent_text_version' => 'v1',
            'actor_user_id' => null,
            'source' => 'web_form',
            'metadata_json' => [],
        ];
    }

    public function forConsent(CommunicationConsent $consent): static
    {
        return $this->state(fn () => [
            'communication_consent_id' => $consent->id,
            'firm_id' => $consent->firm_id,
        ]);
    }
}
