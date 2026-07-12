<?php

namespace Database\Factories;

use App\Enums\PartyEntityType;
use App\Models\Firm;
use App\Models\Party;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    protected $model = Party::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'name' => $this->faker->name(),
            'entity_type' => PartyEntityType::Individual,
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'company' => null,
            'normalized_search_keys' => null,
            'notes' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function company(): static
    {
        return $this->state(fn () => [
            'entity_type' => PartyEntityType::Company,
            'name' => $this->faker->company(),
        ]);
    }

    /**
     * Section 39A-3L Phase B5 — parties has permanent FORCE ROW LEVEL
     * SECURITY, so every INSERT (test or app) must run under the row's
     * own app.current_firm_id context. See ClientFactory::create()'s
     * docblock (the direct template for this override) for the full
     * rationale, including why setDatabaseTenantContextForFirmId() is
     * used instead of setFirmContext()/runWithFirmContext() and why the
     * setting is deliberately left active rather than cleared.
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
}
