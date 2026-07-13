<?php

namespace Tests\Feature\Governance\DeploymentEnvironment;

use App\Enums\GovernanceMappingStatus;
use App\Services\DeploymentModeCoverageMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeploymentModeCoverageMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIRED_KEYS = [
        'saas_firm_isolation_rls_defense_in_depth',
        'saas_plan_license_controls',
        'saas_centralized_platform_billing',
        'saas_strict_support_access',
        'dedicated_signed_offline_license_validation',
        'dedicated_fleet_migration_enrollment',
        'dedicated_version_skew_limit',
        'dedicated_deployment_health_checks',
        'dedicated_customer_type_controls',
        'dedicated_custom_domain_storage_declarations',
        'private_enterprise_custom_data_region',
        'private_enterprise_support_access_rules',
        'private_enterprise_retention',
        'private_enterprise_ai_mode',
        'private_enterprise_degradation_modes_restricted_integrations',
        'private_enterprise_minimum_health_envelope',
        'private_enterprise_security_review',
        'private_enterprise_vendor_requirements',
    ];

    private DeploymentModeCoverageMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeploymentModeCoverageMappingService();
    }

    public function test_all_eighteen_deployment_mode_control_keys_are_declared_explicitly(): void
    {
        $items = $this->service->all();

        $this->assertCount(18, $items);

        $declaredKeys = array_map(fn ($item) => $item->item_key, $items);

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required deployment-mode control key: {$key}");
        }
    }

    public function test_no_duplicate_keys_exist(): void
    {
        $keys = array_map(fn ($item) => $item->item_key, $this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate deployment-mode control key(s) found.');
    }

    public function test_saas_dedicated_and_private_enterprise_accessors_partition_correctly(): void
    {
        $this->assertCount(4, $this->service->saas());
        $this->assertCount(6, $this->service->dedicated());
        $this->assertCount(8, $this->service->privateEnterprise());
    }

    /**
     * Section 39A-3L Stage B (Checkpoints 22-34) activated permanent
     * FORCE ROW LEVEL SECURITY on all 52 originally-prepared tables —
     * $enforcementActive below is therefore now genuinely TRUE, unlike
     * when this test was first written. That does NOT make this
     * control fully Implemented: firm_settings being forced was always
     * a proxy for "is ANY enforcement active," not the actual gating
     * condition — the real reason this control remains
     * PartiallyImplemented is that 61 additional tenant-owned tables
     * discovered by inventory sweeps still have zero RLS preparation
     * (see the rls_prepared_not_enforced gap's own still-open
     * component). This assertion is therefore unconditional now,
     * rather than gated behind $enforcementActive — gating it there
     * made this test silently perform zero assertions the instant
     * enforcement activated, exactly the failure mode this rewrite
     * closes.
     */
    public function test_saas_rls_control_is_not_implemented_while_rls_enforcement_is_inactive(): void
    {
        $row = DB::selectOne(
            'select relforcerowsecurity from pg_class where relname = ?',
            ['firm_settings']
        );
        $enforcementActive = (bool) $row->relforcerowsecurity;

        $this->assertTrue(
            $enforcementActive,
            'firm_settings must have permanent FORCE ROW LEVEL SECURITY active — Section 39A-3L Stage B is complete.'
        );

        $item = $this->service->byKey('saas_firm_isolation_rls_defense_in_depth');

        $this->assertNotSame(
            GovernanceMappingStatus::Implemented,
            $item->status,
            'This control remains PartiallyImplemented — not because enforcement is inactive (it now is), but because 61 uncovered tenant-owned tables still lack any RLS preparation at all.'
        );
    }

    public function test_dedicated_controls_map_to_phase_16_services(): void
    {
        $this->assertSame(\App\Services\LicenseFileValidationService::class, $this->service->byKey('dedicated_signed_offline_license_validation')->owning_class);
        $this->assertSame(\App\Services\FleetMigrationOrchestrationService::class, $this->service->byKey('dedicated_fleet_migration_enrollment')->owning_class);
        $this->assertSame(\App\Services\VersionSkewPolicyService::class, $this->service->byKey('dedicated_version_skew_limit')->owning_class);
        $this->assertSame(\App\Services\DeploymentHealthEnvelopeService::class, $this->service->byKey('dedicated_deployment_health_checks')->owning_class);
        $this->assertSame(\App\Services\DedicatedCustomerTypeApprovalService::class, $this->service->byKey('dedicated_customer_type_controls')->owning_class);
    }

    public function test_private_enterprise_data_region_status_reflects_aws_inspection(): void
    {
        $item = $this->service->byKey('private_enterprise_custom_data_region');

        // AWS inspection confirmed firms.data_region is a real column.
        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(\App\Models\Firm::class, $item->owning_class);

        $firm = new \App\Models\Firm();
        $this->assertContains('data_region', $firm->getFillable());
    }

    public function test_degradation_modes_are_partially_implemented_because_ai_and_sms_whatsapp_are_undeclared(): void
    {
        $item = $this->service->byKey('private_enterprise_degradation_modes_restricted_integrations');

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);

        $declaredTypes = array_map(fn ($case) => $case->value, \App\Enums\IntegrationType::cases());
        $this->assertNotContains('ai_provider', $declaredTypes);
        $this->assertNotContains('sms', $declaredTypes);
        $this->assertNotContains('whatsapp', $declaredTypes);
    }

    public function test_security_review_is_not_applicable_yet(): void
    {
        $item = $this->service->byKey('private_enterprise_security_review');

        $this->assertSame(GovernanceMappingStatus::NotApplicableYet, $item->status);
    }

    public function test_every_mapping_has_evidence_or_notes(): void
    {
        foreach ($this->service->all() as $item) {
            $this->assertNotEmpty($item->notes, "Item {$item->item_key} should have explanatory notes.");
        }
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist'));
    }
}
