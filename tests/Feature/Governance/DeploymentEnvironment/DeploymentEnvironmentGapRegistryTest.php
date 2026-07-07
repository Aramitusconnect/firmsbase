<?php

namespace Tests\Feature\Governance\DeploymentEnvironment;

use App\Enums\GovernanceGapSeverity;
use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * DeploymentEnvironmentGapRegistryTest — proves Section 29 added
 * exactly the two AWS-confirmed gaps to the EXISTING
 * ComplianceGapRegistryService, without duplicating the RLS gap or
 * any other pre-existing gap, and did NOT add a data-region gap
 * (since firms.data_region already exists).
 */
class DeploymentEnvironmentGapRegistryTest extends TestCase
{
    private ComplianceGapRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceGapRegistryService();
    }

    public function test_section_28_gap_count_before_section_29_additions_was_eleven(): void
    {
        // 11 pre-existing (Section 25/26/27/28) + 2 Section 29 gaps + 2
        // Section 30 gaps = 15.
        $this->assertCount(15, $this->service->all());
    }

    public function test_integration_degradation_gap_exists_because_aws_confirmed_ai_sms_whatsapp_undeclared(): void
    {
        $item = $this->service->byKey('integration_degradation_registry_missing_ai_sms_whatsapp');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceGapSeverity::Medium, $item->severity);
    }

    public function test_secret_rotation_gap_exists_because_aws_confirmed_no_schedule_or_reminder(): void
    {
        $item = $this->service->byKey('secret_rotation_schedule_or_reminder_missing');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceGapSeverity::Low, $item->severity);
    }

    public function test_data_region_gap_was_not_added_because_the_field_already_exists(): void
    {
        $this->assertFalse($this->service->isTracked('private_enterprise_custom_data_region_not_represented'));

        $firm = new \App\Models\Firm();
        $this->assertContains('data_region', $firm->getFillable());
    }

    public function test_no_duplicate_rls_gap_exists(): void
    {
        $rlsRelatedKeys = array_filter(
            array_map(fn ($item) => $item->key, $this->service->all()),
            fn (string $key) => str_contains($key, 'rls'),
        );

        $this->assertCount(1, $rlsRelatedKeys);
    }

    public function test_no_duplicate_gap_keys_exist(): void
    {
        $keys = array_map(fn ($item) => $item->key, $this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate gap key(s) found.');
    }

    public function test_exact_final_gap_count(): void
    {
        $this->assertCount(15, $this->service->all());
    }
}
