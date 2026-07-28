<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<ClientPortalMatterGrant>
 *
 * client_portal_matter_grants has permanent FORCE ROW LEVEL SECURITY
 * (database/migrations/2026_09_24_180005_prepare_row_level_security_and_force_rls_on_client_portal_matter_grants_table.php),
 * a direct, own NOT NULL firm_id column — so this create() override
 * mirrors ClientFactory/FirmUserFactory exactly: sets the PostgreSQL
 * session setting for the resolved firm_id (never PHP-memory
 * TenantContextResolver state, which BelongsToTenant's global scope
 * reads) and deliberately leaves it set afterward for the common
 * "create then read" test pattern.
 */
class ClientPortalMatterGrantFactory extends Factory
{
    protected $model = ClientPortalMatterGrant::class;

    public function definition(): array
    {
        $firm = Firm::factory()->create();

        return [
            'firm_id' => $firm->id,
            'client_id' => Client::factory()->forFirm($firm),
            'matter_id' => Matter::factory()->forFirm($firm),
            'granted_by' => null,
            'granted_at' => now(),
            'revoked_at' => null,
        ];
    }

    /**
     * Ties the grant to the given firm — a fresh, unrelated client and
     * matter (both belonging to that firm) are generated unless
     * forClientAndMatter() overrides them. Grants are deliberately
     * independent of matters.client_id (§2.6 point 3 of the design doc
     * — an explicit grant table, not an inferred rule), so a random
     * client/matter pairing here is intentional, not an oversight.
     */
    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'client_id' => Client::factory()->forFirm($firm),
            'matter_id' => Matter::factory()->forFirm($firm),
        ]);
    }

    public function forClientAndMatter(Client $client, Matter $matter): static
    {
        return $this->state(fn () => [
            'firm_id' => $client->firm_id,
            'client_id' => $client->id,
            'matter_id' => $matter->id,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = new TenantContextService;

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }
}
