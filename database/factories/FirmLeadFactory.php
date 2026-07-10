<?php

namespace Database\Factories;

use App\Enums\FirmLeadStatus;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<FirmLead>
 */
class FirmLeadFactory extends Factory
{
    protected $model = FirmLead::class;

    /**
     * Context-hold pattern (Section 39A-3J, matching every prior
     * FORCE-RLS factory since 39A-3A): groups resolved models by
     * firm_id and activates the matching PostgreSQL session context
     * per group before inserting, so a bare
     * FirmLead::factory()->create() works correctly even called from
     * outside any already-active tenant context. Deliberately does
     * not clear context afterward.
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
            'lead_source_id' => null,
            'practice_area_interest_id' => null,
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'status' => FirmLeadStatus::New,
            'assigned_to' => null,
            'converted_client_id' => null,
            'converted_at' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function status(FirmLeadStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
