<?php

namespace Database\Factories;

use App\Enums\LicenseStatus;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<FirmLicense>
 */
class FirmLicenseFactory extends Factory
{
    protected $model = FirmLicense::class;

    /**
     * Section 39A-3L, Checkpoint 19 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A): groups resolved
     * models by firm_id and activates the matching PostgreSQL session
     * context per group before inserting, so a bare
     * FirmLicense::factory()->create() works correctly even called
     * from outside any already-active tenant context.
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
            'billing_account_id' => null,
            'license_key' => strtoupper($this->faker->bothify('LIC-????-####')),
            'license_status' => LicenseStatus::Trial,
            'starts_at' => now(),
            'renews_at' => null,
            'expires_at' => now()->addDays(14),
            'cancelled_at' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function status(LicenseStatus $status): static
    {
        return $this->state(fn () => ['license_status' => $status]);
    }
}
