<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Enums\SyncDirection;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntegrationExternalMapping>
 *
 * integration_external_mappings has permanent FORCE ROW LEVEL SECURITY
 * (see database/migrations/2026_09_05_052002_prepare_row_level_security_and_force_rls_on_integration_external_mappings_table.php),
 * so every INSERT (test or app) must run under the row's own
 * app.current_firm_id context. Mirrors FirmIntegrationFactory's
 * context-hold convention exactly.
 */
class IntegrationExternalMappingFactory extends Factory
{
    protected $model = IntegrationExternalMapping::class;

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
        $firm = Firm::factory()->create();
        $firmIntegration = FirmIntegration::factory()->forFirm($firm)->create();

        return [
            'firm_id' => $firm->id,
            'firm_integration_id' => $firmIntegration->id,
            'resource_type' => 'contact',
            'local_type' => 'App\\Models\\Contact',
            'local_id' => fake()->numberBetween(1, 100000),
            'external_id' => (string) Str::uuid(),
            'sync_direction' => SyncDirection::Inbound->value,
            'last_synced_at' => now(),
        ];
    }

    /**
     * Overrides firm_id AND firm_integration_id together — never
     * independently.
     */
    public function forFirmIntegration(FirmIntegration $firmIntegration): static
    {
        return $this->state(fn () => [
            'firm_id' => $firmIntegration->firm_id,
            'firm_integration_id' => $firmIntegration->id,
        ]);
    }

    public function tombstoned(string $reason = 'external_deleted'): static
    {
        return $this->state(fn () => [
            'tombstoned_at' => now(),
            'tombstone_reason' => $reason,
        ]);
    }
}
