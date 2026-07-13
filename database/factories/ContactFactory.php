<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'client_id' => null,
            'name' => $this->faker->name(),
            'company' => null,
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'role' => null,
            'normalized_search_keys' => null,
            'encrypted_sensitive_fields' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    /**
     * Section 39A-3L Phase B5 — contacts has permanent FORCE ROW LEVEL
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
