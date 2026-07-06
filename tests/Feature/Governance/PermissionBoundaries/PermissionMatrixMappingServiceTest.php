<?php

namespace Tests\Feature\Governance\PermissionBoundaries;

use App\Enums\GovernanceMappingStatus;
use App\Services\PermissionMatrixMappingService;
use Tests\TestCase;

class PermissionMatrixMappingServiceTest extends TestCase
{
    private const REQUIRED_ROLE_KEYS = [
        'org_admin',
        'firm_owner', 'attorney', 'paralegal', 'legal_assistant', 'receptionist', 'billing_staff', 'client',
        'super_admin', 'platform_admin', 'support_agent', 'billing_admin', 'sales_manager', 'sales_rep',
        'implementation_specialist', 'security_auditor', 'read_only_auditor',
    ];

    private PermissionMatrixMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PermissionMatrixMappingService();
    }

    public function test_declares_all_seventeen_role_keys_explicitly(): void
    {
        $items = $this->service->all();

        $this->assertCount(17, $items);

        $declaredKeys = array_map(fn ($item) => $item->item_key, $items);

        foreach (self::REQUIRED_ROLE_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required role key: {$key}");
        }
    }

    public function test_org_admin_is_not_found(): void
    {
        $item = $this->service->byKey('org_admin');

        $this->assertSame(GovernanceMappingStatus::NotFound, $item->status);
        $this->assertNull($item->owning_class);
    }

    public function test_org_admin_is_the_only_role_classified_not_found(): void
    {
        $notFoundKeys = array_map(
            fn ($item) => $item->item_key,
            array_filter($this->service->all(), fn ($item) => $item->status === GovernanceMappingStatus::NotFound),
        );

        $this->assertSame(['org_admin'], array_values($notFoundKeys));
    }

    public function test_firm_roles_map_to_firm_user_role_where_applicable(): void
    {
        foreach (['firm_owner', 'attorney', 'paralegal', 'legal_assistant', 'receptionist', 'billing_staff'] as $key) {
            $item = $this->service->byKey($key);

            $this->assertSame(\App\Enums\FirmUserRole::class, $item->owning_class, "{$key} should map to FirmUserRole");
            $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        }
    }

    public function test_client_maps_to_client_model_separation_not_firm_user_role(): void
    {
        $item = $this->service->byKey('client');

        $this->assertSame(\App\Models\Client::class, $item->owning_class);
        $this->assertNotSame(\App\Enums\FirmUserRole::class, $item->owning_class);
        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
    }

    public function test_all_nine_platform_roles_map_to_platform_role_code(): void
    {
        $platformKeys = [
            'super_admin', 'platform_admin', 'support_agent', 'billing_admin', 'sales_manager',
            'sales_rep', 'implementation_specialist', 'security_auditor', 'read_only_auditor',
        ];

        $this->assertCount(9, $this->service->platformRoles());

        foreach ($platformKeys as $key) {
            $item = $this->service->byKey($key);
            $this->assertSame(\App\Enums\PlatformRoleCode::class, $item->owning_class, "{$key} should map to PlatformRoleCode");
        }
    }

    public function test_sales_manager_and_sales_rep_are_partially_implemented(): void
    {
        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $this->service->byKey('sales_manager')->status);
        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $this->service->byKey('sales_rep')->status);
    }

    public function test_support_agent_notes_the_emergency_access_gap(): void
    {
        $item = $this->service->byKey('support_agent');

        $this->assertStringContainsString('EMERGENCY', $item->notes);
        $this->assertStringContainsString('EmergencyAccessGovernanceGapService', $item->notes);
    }

    public function test_organization_firm_and_platform_role_accessors_partition_correctly(): void
    {
        $this->assertCount(1, $this->service->organizationRoles());
        $this->assertCount(7, $this->service->firmRoles());
        $this->assertCount(9, $this->service->platformRoles());
    }

    public function test_client_boundary_returns_the_client_result(): void
    {
        $this->assertSame('client', $this->service->clientBoundary()->item_key);
    }

    public function test_gaps_includes_org_admin_sales_roles_and_support_agent(): void
    {
        $gapKeys = array_map(fn ($item) => $item->item_key, $this->service->gaps());

        $this->assertContains('org_admin', $gapKeys);
        $this->assertContains('sales_manager', $gapKeys);
        $this->assertContains('sales_rep', $gapKeys);
        $this->assertContains('support_agent', $gapKeys);
    }
}
