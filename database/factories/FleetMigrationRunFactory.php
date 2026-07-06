<?php

namespace Database\Factories;

use App\Enums\FleetMigrationRunStatus;
use App\Models\FleetMigrationRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FleetMigrationRun>
 */
class FleetMigrationRunFactory extends Factory
{
    protected $model = FleetMigrationRun::class;

    public function definition(): array
    {
        return [
            'migration_identifier' => '2026_07_25_900001_example_migration',
            'status' => FleetMigrationRunStatus::Pending,
            'initiated_by' => User::factory(),
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
