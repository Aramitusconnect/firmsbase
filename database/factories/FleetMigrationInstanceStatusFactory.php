<?php

namespace Database\Factories;

use App\Enums\FleetMigrationInstanceStatus as FleetMigrationInstanceStatusEnum;
use App\Models\Firm;
use App\Models\FleetMigrationInstanceStatus;
use App\Models\FleetMigrationRun;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<FleetMigrationInstanceStatus>
 */
class FleetMigrationInstanceStatusFactory extends Factory
{
    protected $model = FleetMigrationInstanceStatus::class;

    /**
     * fleet_migration_instance_status has permanent FORCE ROW LEVEL
     * SECURITY (see database/migrations/2026_08_29_970005_prepare_row_
     * level_security_and_force_rls_on_fleet_migration_instance_status_table.php),
     * so every INSERT (test or app) must run under the row's own
     * app.current_firm_id context. See MatterFactory::create()'s
     * docblock for the full rationale, including why
     * setDatabaseTenantContextForFirmId() is used instead of
     * setFirmContext()/runWithFirmContext() and why the setting is
     * deliberately left active rather than cleared. Grouping by
     * firm_id here works fine despite this table's additional
     * unique(['fleet_migration_run_id', 'firm_id']) composite
     * constraint — that constraint only prevents two rows sharing both
     * columns, not two firm-scoped groups being created within the
     * same batch.
     */
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

    public function definition(): array
    {
        return [
            'fleet_migration_run_id' => FleetMigrationRun::factory(),
            'firm_id' => Firm::factory(),
            'status' => FleetMigrationInstanceStatusEnum::Pending,
        ];
    }

    public function forRun(FleetMigrationRun $run): static
    {
        return $this->state(fn () => ['fleet_migration_run_id' => $run->id]);
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
