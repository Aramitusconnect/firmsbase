<?php

namespace Database\Factories;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\Client;
use App\Models\CommunicationConsent;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<CommunicationConsent>
 */
class CommunicationConsentFactory extends Factory
{
    protected $model = CommunicationConsent::class;

    /**
     * Section 39A-3L, Checkpoint 11 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A): groups resolved
     * models by firm_id and activates the matching PostgreSQL session
     * context per group before inserting, so a bare
     * CommunicationConsent::factory()->create() works correctly even
     * called from outside any already-active tenant context. No
     * cross-firm mismatch exists in definition() itself (client_id
     * defaults to null), so this override is purely the context-hold
     * wrapper — no field-derivation fix needed.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);
        $models = $results instanceof Model ? new Collection([$results]) : $results;
        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'client_id' => null,
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

    public function channel(ConsentChannel $channel): static
    {
        return $this->state(fn () => [
            'channel' => $channel,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => ConsentStatus::Revoked,
            'granted_at' => now()->subDays(2),
            'revoked_at' => now()->subDay(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => ConsentStatus::Granted,
            'granted_at' => now()->subDays(10),
            'expires_at' => now()->subDay(),
        ]);
    }
}
