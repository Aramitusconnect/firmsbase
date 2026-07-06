<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\PrivateEnterpriseSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrivateEnterpriseSettings>
 */
class PrivateEnterpriseSettingsFactory extends Factory
{
    protected $model = PrivateEnterpriseSettings::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'requires_custom_domain' => false,
            'requires_isolated_database' => false,
            'requires_isolated_storage' => false,
            'telemetry_prohibited' => false,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function telemetryProhibited(): static
    {
        return $this->state(fn () => ['telemetry_prohibited' => true]);
    }
}
