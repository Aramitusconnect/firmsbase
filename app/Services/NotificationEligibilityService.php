<?php

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\Firm;
use App\Services\ConsentService;
use App\ValueObjects\DunningEligibility;

/**
 * NotificationEligibilityService — the consent/preference/do-not-
 * contact/suppression gate every outbound notification must pass,
 * reusing Phase 1/2's ConsentService and ClientCommunicationPreference
 * exclusively — no second consent/preference system (project rule).
 *
 * Returns Phase 3's DunningEligibility VO rather than a new
 * near-identical class — the shape (eligible/reason/channel/language/
 * timezone) is exactly what both a payment-plan dunning check and a
 * general notification eligibility check need. DocumentChaseService
 * also reuses this same VO.
 */
class NotificationEligibilityService
{
    public function __construct(
        private ConsentService $consent,
        private SuppressionService $suppression,
    ) {
    }

    public function check(Firm $firm, Client $client, ConsentChannel $channel, string $recipient): DunningEligibility
    {
        $preference = ClientCommunicationPreference::query()
            ->where('firm_id', $firm->id)
            ->where('client_id', $client->id)
            ->first();

        if ($preference?->do_not_contact) {
            return new DunningEligibility(
                eligible: false,
                reason: 'do_not_contact flag is set',
                channel: $channel,
                clientLanguage: $client->preferred_language,
                clientTimezone: $client->preferred_timezone,
            );
        }

        if (! $this->consent->isGranted($firm, $client->id, $channel)) {
            return new DunningEligibility(
                eligible: false,
                reason: "no granted consent for channel {$channel->value}",
                channel: $channel,
                clientLanguage: $client->preferred_language,
                clientTimezone: $client->preferred_timezone,
            );
        }

        if ($this->suppression->isSuppressed($firm, $recipient, $channel)) {
            return new DunningEligibility(
                eligible: false,
                reason: "recipient {$recipient} is suppressed on channel {$channel->value}",
                channel: $channel,
                clientLanguage: $client->preferred_language,
                clientTimezone: $client->preferred_timezone,
            );
        }

        return new DunningEligibility(
            eligible: true,
            channel: $channel,
            clientLanguage: $client->preferred_language,
            clientTimezone: $client->preferred_timezone,
        );
    }
}
