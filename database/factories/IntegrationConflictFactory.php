<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Enums\ConflictStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<IntegrationConflict>
 *
 * integration_conflicts has permanent FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_09_05_054002_prepare_row_level_security_and_force_rls_on_integration_conflicts_table.php),
 * so every INSERT (test or app) must run under the row's own
 * app.current_firm_id context. Mirrors FirmIntegrationFactory's
 * context-hold convention exactly.
 *
 * Default resource_type is 'contact' (non-privileged) precisely so the
 * default, unmodified factory output does not trip the
 * privileged-resource CHECK constraints — a caller that specifically
 * wants a privileged/financial-resource conflict must opt in via
 * ->privilegedResource() or ->resolved(), which correctly wire the
 * dual-approval columns together.
 */
class IntegrationConflictFactory extends Factory
{
    protected $model = IntegrationConflict::class;

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

    /**
     * Audit fix (eager-factory-side-effects audit): this used to call
     * Firm::factory()->create() and
     * FirmIntegration::factory()->forFirm($firm)->create() as plain PHP
     * statements at the top of definition() — real, committed rows
     * every single time, even when forFirmIntegration() below
     * immediately overrides both firm_id and firm_integration_id with a
     * caller-supplied connection. Fixed by memoizing the pair behind
     * lazy closures so nothing is created unless at least one of those
     * keys survives, unoverridden, to the final row.
     */
    private ?FirmIntegration $lazyFirmIntegration = null;

    public function definition(): array
    {
        $this->lazyFirmIntegration = null;

        return [
            'firm_id' => function () {
                $this->lazyFirmIntegration ??= FirmIntegration::factory()->create();

                return $this->lazyFirmIntegration->firm_id;
            },
            'firm_integration_id' => function () {
                $this->lazyFirmIntegration ??= FirmIntegration::factory()->create();

                return $this->lazyFirmIntegration->id;
            },
            'sync_item_id' => null,
            'external_mapping_id' => null,
            'resource_type' => 'contact',
            'local_type' => 'App\\Models\\Contact',
            'local_id' => fake()->numberBetween(1, 100000),
            'conflict_type' => 'field_value_mismatch',
            'local_value' => ['name' => 'Local Name'],
            'external_value' => ['name' => 'External Name'],
            'status' => ConflictStatus::Detected->value,
            'requires_manual_review' => false,
            'detected_at' => now(),
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

    public function privilegedResource(): static
    {
        return $this->state(fn () => [
            'resource_type' => 'document',
            'requires_manual_review' => true,
        ]);
    }

    /**
     * Sets ONLY the resolving-actor identity pair, mirroring
     * IntegrationOAuthStateFactory::initiatedBy()'s identical pattern
     * for a bare-FK-to-firm_users column. $approver, when supplied,
     * MUST belong to the same firm and be distinct from $resolver, per
     * this table's own dual-approval CHECK constraint.
     */
    public function resolvedBy(FirmUser $resolver, ?FirmUser $approver = null): static
    {
        return $this->state(fn () => [
            'status' => ConflictStatus::ResolvedLocalWins->value,
            'resolved_by_firm_user_id' => $resolver->id,
            'resolution_approved_by_firm_user_id' => $approver?->id,
            'resolved_at' => now(),
        ]);
    }
}
