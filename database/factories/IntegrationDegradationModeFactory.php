<?php

namespace Database\Factories;

use App\Enums\DegradedBehavior;
use App\Enums\IntegrationType;
use App\Models\IntegrationDegradationMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationDegradationMode>
 */
class IntegrationDegradationModeFactory extends Factory
{
    protected $model = IntegrationDegradationMode::class;

    public function definition(): array
    {
        return [
            'integration_type' => IntegrationType::Stripe,
            'degraded_behavior' => DegradedBehavior::QueueAndRetry,
            'description' => 'Test fixture degradation mode.',
        ];
    }
}
