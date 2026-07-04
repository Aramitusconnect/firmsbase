<?php

namespace Database\Factories;

use App\Enums\BackupRestoreTestStatus;
use App\Models\BackupRestoreTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRestoreTest>
 */
class BackupRestoreTestFactory extends Factory
{
    protected $model = BackupRestoreTest::class;

    public function definition(): array
    {
        return [
            'firm_id' => null,
            'status' => BackupRestoreTestStatus::Passed,
            'components_verified_json' => ['database_records', 'documents', 'app_configuration', 'queues', 'tenant_settings', 'critical_logs'],
            'rpo_target_seconds' => 86400,
            'rto_target_seconds' => 28800,
            'rpo_actual_seconds' => 3600,
            'rto_actual_seconds' => 7200,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
            'notes' => 'Simulated drill.',
            'created_by' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => BackupRestoreTestStatus::Failed]);
    }

    public function missingTargets(): static
    {
        return $this->state(fn () => [
            'rpo_actual_seconds' => 100000,
            'rto_actual_seconds' => 40000,
        ]);
    }
}
