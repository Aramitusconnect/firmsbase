<?php

namespace Database\Factories;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\ModuleCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FirmEntitlement>
 */
class FirmEntitlementFactory extends Factory
{
    protected $model = FirmEntitlement::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            // NOT ModuleCatalog::factory() directly — that shorthand
            // resolves to the related model's primary key (bigint id),
            // but module_code is a string FK to module_catalog.module_code.
            'module_code' => fn () => ModuleCatalog::factory()->create()->module_code,
            'enabled' => true,
            'source' => EntitlementSource::AdminOverride,
            'settings_json' => [],
            'starts_at' => null,
            'ends_at' => null,
            'created_by' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function forModule(ModuleCatalog $module): static
    {
        return $this->state(fn () => ['module_code' => $module->module_code]);
    }

    public function source(EntitlementSource $source): static
    {
        return $this->state(fn () => ['source' => $source]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
