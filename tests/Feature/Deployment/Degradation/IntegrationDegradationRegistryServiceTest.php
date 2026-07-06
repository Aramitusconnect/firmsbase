<?php

namespace Tests\Feature\Deployment\Degradation;

use App\Enums\DegradedBehavior;
use App\Enums\IntegrationType;
use App\Services\IntegrationDegradationRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntegrationDegradationRegistryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_four_integration_modes_are_seeded(): void
    {
        $this->assertDatabaseCount('integration_degradation_modes', 4);
    }

    public function test_every_integration_type_has_a_declared_behavior(): void
    {
        $this->assertTrue(app(IntegrationDegradationRegistryService::class)->everyIntegrationHasADeclaredMode());
    }

    #[DataProvider('integrationTypeProvider')]
    public function test_behavior_for_returns_a_valid_degraded_behavior(IntegrationType $type): void
    {
        $behavior = app(IntegrationDegradationRegistryService::class)->behaviorFor($type);

        $this->assertInstanceOf(DegradedBehavior::class, $behavior);
    }

    public static function integrationTypeProvider(): array
    {
        return [
            'stripe' => [IntegrationType::Stripe],
            'email provider' => [IntegrationType::EmailProvider],
            'virus scanning' => [IntegrationType::VirusScanning],
            'telemetry' => [IntegrationType::Telemetry],
        ];
    }

    public function test_seed_migration_is_idempotent(): void
    {
        // Load and re-run the migration's up() directly (matching the
        // convention used to verify Phase 14/15's own idempotent
        // module_catalog seed migrations) rather than through
        // Artisan::call(), which tracks already-run migrations by
        // batch and would not naturally re-execute this one in a test.
        $path = base_path('database/migrations/2026_07_25_900009_seed_phase16_integration_degradation_modes.php');
        $migration = require $path;

        $migration->up();
        $migration->up();

        $this->assertDatabaseCount('integration_degradation_modes', 4);
    }
}
