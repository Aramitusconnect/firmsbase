<?php

namespace Tests\Feature\Governance\PermissionBoundaries;

use App\Enums\PlatformRoleCode;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\PlatformStaffAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformStaffAccessBoundaryTest — regression coverage proving the
 * Section 27 permission-matrix classifications (sales roles deny-safe,
 * billing_admin billing-only, read_only_auditor never mutates,
 * security_auditor logs-only-by-default) hold against the ACTUAL
 * public methods of the existing, unmodified PlatformStaffAccessPolicyService.
 */
class PlatformStaffAccessBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private PlatformStaffAccessPolicyService $service;
    private PlatformRoleService $roleService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roleService = new PlatformRoleService();
        $this->service = new PlatformStaffAccessPolicyService($this->roleService);
    }

    public function test_sales_manager_cannot_access_client_matter_or_document_data(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SalesManager);

        $this->assertFalse($this->service->canAccessClientData($admin)->allowed);
        $this->assertFalse($this->service->canAccessMatterData($admin)->allowed);
        $this->assertFalse($this->service->canAccessDocumentContent($admin)->allowed);
    }

    public function test_sales_rep_cannot_access_client_matter_or_document_data(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SalesRep);

        $this->assertFalse($this->service->canAccessClientData($admin)->allowed);
        $this->assertFalse($this->service->canAccessMatterData($admin)->allowed);
        $this->assertFalse($this->service->canAccessDocumentContent($admin)->allowed);
    }

    public function test_sales_roles_also_cannot_access_platform_billing_or_security_logs(): void
    {
        foreach ([PlatformRoleCode::SalesManager, PlatformRoleCode::SalesRep] as $role) {
            $admin = PlatformAdmin::factory()->create();
            $this->roleService->grant($admin, $role);

            $this->assertFalse($this->service->canAccessPlatformBilling($admin)->allowed, "{$role->value} must not access platform billing");
            $this->assertFalse($this->service->canAccessSecurityLogs($admin)->allowed, "{$role->value} must not access security logs");
        }
    }

    public function test_billing_admin_can_access_platform_billing_but_not_document_content(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::BillingAdmin);

        $this->assertTrue($this->service->canAccessPlatformBilling($admin)->allowed);
        $this->assertFalse($this->service->canAccessDocumentContent($admin)->allowed);
    }

    public function test_read_only_auditor_cannot_mutate(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::ReadOnlyAuditor);

        $this->assertFalse($this->service->canMutate($admin)->allowed);
    }

    public function test_read_only_auditor_cannot_mutate_even_when_holding_another_role(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->roleService->grant($admin, PlatformRoleCode::SuperAdmin);

        $this->assertFalse($this->service->canMutate($admin)->allowed);
    }

    public function test_security_auditor_can_access_security_logs_but_not_document_content_by_default(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SecurityAuditor);

        $this->assertTrue($this->service->canAccessSecurityLogs($admin)->allowed);
        $this->assertFalse($this->service->canAccessDocumentContent($admin)->allowed);
        $this->assertTrue($this->service->canAccessDocumentContent($admin, hasGovernedSupportAccess: true)->allowed);
    }
}
