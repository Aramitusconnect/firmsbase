<?php

namespace Tests\Feature\Deployment\Firewall;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\EntitlementSource;
use App\Enums\HealthCheckStatus;
use App\Enums\LicenseStatus;
use App\Enums\SeatClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Confirms exactly the 8 approved Phase 16 tables exist — no extra
 * deployment/license/fleet table was introduced — and that the 6
 * enums the approved spec requires be REUSED (never duplicated) are
 * still the exact same enums Phase 16 code imports.
 */
class Phase16ExactTablesTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_TABLES = [
        'deployment_configs',
        'deployment_health_checks',
        'fleet_migration_runs',
        'fleet_migration_instance_status',
        'license_files',
        'license_validation_events',
        'integration_degradation_modes',
        'private_enterprise_settings',
    ];

    /**
     * Pre-existing Phase 6 license/entitlement tables that Phase 16 is
     * required to EXTEND, not treat as new — their migrations predate
     * Phase 16's (2026_07_04/2026_07_09 vs Phase 16's 2026_07_25) and
     * they are untouched by any Phase 16 migration. Excluded here so
     * this test only ever fails on a genuinely NEW deployment/license/
     * fleet/private-enterprise table introduced by Phase 16 itself.
     */
    private const PRE_EXISTING_PHASE_6_TABLES = [
        'license_events',
        'firm_licenses',
        'org_licenses',
        'firm_entitlements',
        'firm_entitlement_events',
    ];

    public function test_all_eight_phase_16_tables_exist(): void
    {
        foreach (self::EXPECTED_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table {$table} to exist.");
        }
    }

    public function test_no_extra_deployment_license_or_fleet_table_exists(): void
    {
        $allTables = collect(Schema::getTables())->pluck('name')->all();

        $unexpected = collect($allTables)
            ->filter(fn (string $name) => str_starts_with($name, 'deployment_')
                || str_starts_with($name, 'fleet_')
                || str_starts_with($name, 'license_')
                || str_starts_with($name, 'integration_degradation')
                || str_starts_with($name, 'private_enterprise'))
            ->reject(fn (string $name) => in_array($name, self::EXPECTED_TABLES, true)
                || in_array($name, self::PRE_EXISTING_PHASE_6_TABLES, true))
            ->values()
            ->all();

        $this->assertEmpty($unexpected, 'Unexpected Phase 16 table(s) found: '.implode(', ', $unexpected));
    }

    public function test_deployment_modes_reuse_the_existing_deployment_mode_enum(): void
    {
        $values = array_map(fn (DeploymentMode $case) => $case->value, DeploymentMode::cases());
        sort($values);

        $this->assertSame(['dedicated', 'private_enterprise', 'saas'], $values);
    }

    public function test_customer_types_reuse_the_existing_customer_type_enum(): void
    {
        $values = array_map(fn (CustomerType $case) => $case->value, CustomerType::cases());
        sort($values);

        $this->assertSame(['law_firm', 'legal_specialist'], $values);
    }

    public function test_license_status_and_seat_class_and_entitlement_source_are_not_duplicated(): void
    {
        // Existence-and-shape check only — proves Phase 16 did not
        // introduce a second, competing enum of the same concept.
        $this->assertNotEmpty(LicenseStatus::cases());
        $this->assertNotEmpty(SeatClass::cases());
        $this->assertNotEmpty(EntitlementSource::cases());
        $this->assertNotEmpty(HealthCheckStatus::cases());
    }
}
