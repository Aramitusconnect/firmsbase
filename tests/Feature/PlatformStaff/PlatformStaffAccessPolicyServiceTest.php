<?php

namespace Tests\Feature\PlatformStaff;

use App\Enums\PlatformRoleCode;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\PlatformStaffAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformStaffAccessPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformStaffAccessPolicyService $service;

    private PlatformRoleService $roleService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roleService = new PlatformRoleService;
        $this->service = new PlatformStaffAccessPolicyService($this->roleService);
    }

    public function test_sales_rep_cannot_access_client_matter_or_document_data(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SalesRep);

        $this->assertFalse($this->service->canAccessClientData($admin)->allowed);
        $this->assertFalse($this->service->canAccessMatterData($admin)->allowed);
        $this->assertFalse($this->service->canAccessDocumentContent($admin)->allowed);
    }

    public function test_billing_admin_can_access_platform_billing_but_not_document_contents(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::BillingAdmin);

        $this->assertTrue($this->service->canAccessPlatformBilling($admin)->allowed);
        $this->assertFalse($this->service->canAccessDocumentContent($admin)->allowed);
    }

    public function test_security_auditor_can_access_security_logs_but_not_document_contents_without_governed_access(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SecurityAuditor);

        $this->assertTrue($this->service->canAccessSecurityLogs($admin)->allowed);
        $this->assertFalse($this->service->canAccessDocumentContent($admin)->allowed);
        $this->assertTrue($this->service->canAccessDocumentContent($admin, hasGovernedSupportAccess: true)->allowed);
    }

    public function test_read_only_auditor_must_not_mutate(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::ReadOnlyAuditor);

        $this->assertFalse($this->service->canMutate($admin)->allowed);
    }

    public function test_super_admin_can_access_everything(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SuperAdmin);

        $this->assertTrue($this->service->canAccessClientData($admin)->allowed);
        $this->assertTrue($this->service->canAccessMatterData($admin)->allowed);
        $this->assertTrue($this->service->canAccessDocumentContent($admin)->allowed);
        $this->assertTrue($this->service->canAccessPlatformBilling($admin)->allowed);
        $this->assertTrue($this->service->canAccessSecurityLogs($admin)->allowed);
        $this->assertTrue($this->service->canMutate($admin)->allowed);
        $this->assertTrue($this->service->canAccessPlatformAdministration($admin)->allowed);
        $this->assertTrue($this->service->canManageFirms($admin)->allowed);
        $this->assertTrue($this->service->canManagePlatformAdministrators($admin)->allowed);
        $this->assertTrue($this->service->canManageRoles($admin)->allowed);
    }

    // ------------------------------------------------------------
    // Phase 1 FirmsVault Admin Control Center additions
    // ------------------------------------------------------------

    public function test_support_agent_and_billing_admin_can_view_platform_administration_but_not_manage_firms(): void
    {
        $supportAgent = PlatformAdmin::factory()->create();
        $this->roleService->grant($supportAgent, PlatformRoleCode::SupportAgent);

        $billingAdmin = PlatformAdmin::factory()->create();
        $this->roleService->grant($billingAdmin, PlatformRoleCode::BillingAdmin);

        foreach ([$supportAgent, $billingAdmin] as $admin) {
            $this->assertTrue($this->service->canAccessPlatformAdministration($admin)->allowed);
            $this->assertFalse($this->service->canManageFirms($admin)->allowed);
            $this->assertFalse($this->service->canManagePlatformAdministrators($admin)->allowed);
            $this->assertFalse($this->service->canManageRoles($admin)->allowed);
        }
    }

    public function test_sales_roles_cannot_view_platform_administration(): void
    {
        $salesManager = PlatformAdmin::factory()->create();
        $this->roleService->grant($salesManager, PlatformRoleCode::SalesManager);

        $salesRep = PlatformAdmin::factory()->create();
        $this->roleService->grant($salesRep, PlatformRoleCode::SalesRep);

        $this->assertFalse($this->service->canAccessPlatformAdministration($salesManager)->allowed);
        $this->assertFalse($this->service->canAccessPlatformAdministration($salesRep)->allowed);
    }

    public function test_platform_admin_role_can_manage_firms_but_not_platform_administrators_or_roles(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::PlatformAdmin);

        $this->assertTrue($this->service->canAccessPlatformAdministration($admin)->allowed);
        $this->assertTrue($this->service->canManageFirms($admin)->allowed);
        $this->assertFalse(
            $this->service->canManagePlatformAdministrators($admin)->allowed,
            'Only SuperAdmin may manage other PlatformAdmins — even the platform_admin role itself must not.'
        );
        $this->assertFalse($this->service->canManageRoles($admin)->allowed);
    }

    public function test_a_platform_admin_with_no_role_is_denied_platform_administration(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->assertFalse($this->service->canAccessPlatformAdministration($admin)->allowed);
        $this->assertNotNull($this->service->canAccessPlatformAdministration($admin)->reason);
    }
}
