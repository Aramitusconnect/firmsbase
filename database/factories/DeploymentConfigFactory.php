<?php

namespace Database\Factories;

use App\Enums\BootCheckStatus;
use App\Models\DeploymentConfig;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeploymentConfig>
 */
class DeploymentConfigFactory extends Factory
{
    protected $model = DeploymentConfig::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'custom_domain' => null,
            'isolated_database' => false,
            'isolated_storage' => false,
            'custom_retention_policy_json' => null,
            'custom_support_access_json' => null,
            'custom_compliance_settings_json' => null,
            'boot_check_status' => BootCheckStatus::NotYetRun,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
