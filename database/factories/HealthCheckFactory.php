<?php

namespace Database\Factories;

use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Models\HealthCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthCheck>
 */
class HealthCheckFactory extends Factory
{
    protected $model = HealthCheck::class;

    public function definition(): array
    {
        return [
            'firm_id' => null,
            'check_type' => HealthCheckType::WebUptime,
            'status' => HealthCheckStatus::Healthy,
            'detail' => null,
            'checked_at' => now(),
            'metadata_json' => [],
        ];
    }

    public function unhealthy(): static
    {
        return $this->state(fn () => ['status' => HealthCheckStatus::Unhealthy]);
    }
}
