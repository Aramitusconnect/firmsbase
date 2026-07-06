<?php

namespace Database\Factories;

use App\Enums\DeploymentHealthReportMode;
use App\Enums\HealthCheckStatus;
use App\Models\DeploymentHealthCheck;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeploymentHealthCheck>
 */
class DeploymentHealthCheckFactory extends Factory
{
    protected $model = DeploymentHealthCheck::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'heartbeat_at' => now(),
            'version' => '2026.7.0',
            'migration_status' => 'completed',
            'status' => HealthCheckStatus::Healthy,
            'reported_via' => DeploymentHealthReportMode::Live,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function offlineReport(): static
    {
        return $this->state(fn () => ['reported_via' => DeploymentHealthReportMode::OfflineReport]);
    }
}
