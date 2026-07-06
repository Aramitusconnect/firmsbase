<?php

namespace Database\Factories;

use App\Enums\FleetMigrationInstanceStatus as FleetMigrationInstanceStatusEnum;
use App\Models\Firm;
use App\Models\FleetMigrationInstanceStatus;
use App\Models\FleetMigrationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FleetMigrationInstanceStatus>
 */
class FleetMigrationInstanceStatusFactory extends Factory
{
    protected $model = FleetMigrationInstanceStatus::class;

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
