<?php

namespace Tests\Feature\Deployment\Configs;

use App\Enums\BootCheckStatus;
use App\Enums\DeploymentMode;
use App\Models\PrivateEnterpriseSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Deployment\Concerns\SetsUpDeploymentFirm;
use Tests\TestCase;

class DeploymentConfigTest extends TestCase
{
    use RefreshDatabase, SetsUpDeploymentFirm;

    public function test_boot_check_status_defaults_to_not_yet_run(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);

        // deployment_configs now carries permanent FORCE ROW LEVEL
        // SECURITY — the bare create() here has no ambient tenant
        // context, so it is wrapped narrowly (same established
        // precedent as SetsUpDeploymentFirm's own firmSettings() wrap).
        $config = $this->runWithFirmContext($firm, fn () => $firm->deploymentConfig()->create([]));

        $config = $this->runWithFirmContext($firm, fn () => $config->fresh());

        $this->assertSame(BootCheckStatus::NotYetRun, $config->boot_check_status);
    }

    public function test_boot_check_status_can_be_updated_to_passed_or_failed(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $config = $this->runWithFirmContext($firm, fn () => $firm->deploymentConfig()->create([]));

        $config = $this->runWithFirmContext($firm, function () use ($config) {
            $config->update(['boot_check_status' => BootCheckStatus::Passed]);

            return $config->fresh();
        });

        $this->assertSame(BootCheckStatus::Passed, $config->boot_check_status);
    }

    public function test_private_enterprise_settings_declare_custom_domain_isolated_database_and_storage(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::PrivateEnterprise);

        $settings = PrivateEnterpriseSettings::factory()->forFirm($firm)->create([
            'requires_custom_domain' => true,
            'requires_isolated_database' => true,
            'requires_isolated_storage' => true,
        ]);

        $this->assertTrue($settings->fresh()->requires_custom_domain);
        $this->assertTrue($settings->fresh()->requires_isolated_database);
        $this->assertTrue($settings->fresh()->requires_isolated_storage);
    }

    public function test_private_enterprise_settings_default_every_requirement_to_false(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::PrivateEnterprise);

        $settings = PrivateEnterpriseSettings::factory()->forFirm($firm)->create();

        $this->assertFalse($settings->fresh()->requires_custom_domain);
        $this->assertFalse($settings->fresh()->requires_isolated_database);
        $this->assertFalse($settings->fresh()->requires_isolated_storage);
        $this->assertFalse($settings->fresh()->telemetry_prohibited);
    }

    public function test_deployment_config_isolated_database_and_storage_are_declarations_only(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::PrivateEnterprise);
        $config = $this->runWithFirmContext(
            $firm,
            fn () => $firm->deploymentConfig()->create(['isolated_database' => true, 'isolated_storage' => true]),
        );

        // Declaration only — no real database/storage provisioning
        // service exists anywhere in this phase's file set.
        $config = $this->runWithFirmContext($firm, fn () => $config->fresh());
        $this->assertTrue($config->isolated_database);
        $this->assertTrue($config->isolated_storage);

        $servicesDir = app_path('Services');
        $files = glob($servicesDir.'/*.php');

        foreach ($files as $file) {
            $source = file_get_contents($file);
            if (str_starts_with(basename($file), 'Deployment') || str_starts_with(basename($file), 'License') || str_starts_with(basename($file), 'Fleet')) {
                $this->assertStringNotContainsString('CREATE DATABASE', $source);
                $this->assertStringNotContainsString('mkdir(', $source);
            }
        }
    }
}
