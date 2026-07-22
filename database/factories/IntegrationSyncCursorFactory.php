<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Enums\CursorStatus;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncCursor;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<IntegrationSyncCursor>
 *
 * integration_sync_cursors has permanent FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_09_05_053002_prepare_row_level_security_and_force_rls_on_integration_sync_cursors_table.php),
 * so every INSERT (test or app) must run under the row's own
 * app.current_firm_id context. Mirrors FirmIntegrationFactory's
 * context-hold convention exactly.
 */
class IntegrationSyncCursorFactory extends Factory
{
    protected $model = IntegrationSyncCursor::class;

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
            'sync_direction' => SyncDirection::Inbound->value,
            'cursor_value' => null,
            'cursor_version' => 0,
            'status' => CursorStatus::Idle->value,
            'consecutive_failure_count' => 0,
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

    public function withCursorValue(string $value): static
    {
        return $this->state(fn () => ['cursor_value' => $value]);
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => CursorStatus::Running->value,
            'locked_at' => now(),
        ]);
    }

    public function invalid(): static
    {
        return $this->state(fn () => [
            'status' => CursorStatus::Invalid->value,
            'cursor_value' => null,
        ]);
    }
}
