<?php

namespace Database\Factories;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\ModuleCatalog;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<FirmEntitlement>
 */
class FirmEntitlementFactory extends Factory
{
    protected $model = FirmEntitlement::class;

    /**
     * Section 39A-3L, Checkpoint 4 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A, e.g.
     * FirmActivationEventFactory from Checkpoint 3): groups resolved
     * models by firm_id and activates the matching PostgreSQL session
     * context per group before inserting, so a bare
     * FirmEntitlement::factory()->create() works correctly even called
     * from outside any already-active tenant context. Deliberately does
     * not clear context afterward. definition()'s only tenant FK is
     * firm_id itself (via Firm::factory()) — module_code correctly
     * derives from a real, independently-created ModuleCatalog row
     * (module_catalog is confirmed genuinely global/non-tenant, so
     * there is no cross-firm mismatch risk there).
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
