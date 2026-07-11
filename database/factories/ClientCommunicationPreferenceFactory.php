<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<ClientCommunicationPreference>
 */
class ClientCommunicationPreferenceFactory extends Factory
{
    protected $model = ClientCommunicationPreference::class;

    /**
     * Section 39A-3K — context-hold pattern (matching every prior
     * FORCE-RLS factory since 39A-3A): groups resolved models by
     * firm_id and activates the matching PostgreSQL session context per
     * group before inserting, so a bare
     * ClientCommunicationPreference::factory()->create() works
     * correctly even called from outside any already-active tenant
     * context. Deliberately does not clear context afterward.
     * forClient()/withClient() below were verified directly (not just
     * assumed) to already derive firm_id from the client consistently —
     * no ownership-consistency bug found to fix here.
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
