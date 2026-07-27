<?php

namespace Tests\Feature\PlatformStaff;

use App\Enums\PlatformRoleCode;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\PlatformStaffAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OperationsAccessPolicyGatesTest — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). Dedicated test file (rather than
 * appending to the shared PlatformStaffAccessPolicyServiceTest.php,
 * which other parallel Phase 4 passes are concurrently editing in this
 * same worktree) covering the two new gates this pass added:
 * canAccessOperations()/canManageOperations().
 */
class OperationsAccessPolicyGatesTest extends TestCase
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

    public function test_super_admin_platform_admin_security_auditor_and_read_only_auditor_can_access_operations(): void
    {
        foreach ([
            PlatformRoleCode::SuperAdmin,
            PlatformRoleCode::PlatformAdmin,
            PlatformRoleCode::SecurityAuditor,
            PlatformRoleCode::ReadOnlyAuditor,
        ] as $role) {
            $admin = PlatformAdmin::factory()->create();
            $this->roleService->grant($admin, $role);

            $this->assertTrue($this->service->canAccessOperations($admin)->allowed, "{$role->value} must be able to access Operations.");
        }
    }

    public function test_sales_rep_sales_manager_billing_admin_support_agent_and_implementation_specialist_cannot_access_operations(): void
    {
        foreach ([
            PlatformRoleCode::SalesRep,
            PlatformRoleCode::SalesManager,
            PlatformRoleCode::BillingAdmin,
            PlatformRoleCode::SupportAgent,
            PlatformRoleCode::ImplementationSpecialist,
        ] as $role) {
            $admin = PlatformAdmin::factory()->create();
            $this->roleService->grant($admin, $role);

            $this->assertFalse($this->service->canAccessOperations($admin)->allowed, "{$role->value} must NOT be able to access Operations.");
        }
    }

    public function test_a_platform_admin_with_no_role_cannot_access_operations(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->assertFalse($this->service->canAccessOperations($admin)->allowed);
    }

    public function test_super_admin_and_platform_admin_can_manage_operations(): void
    {
        foreach ([PlatformRoleCode::SuperAdmin, PlatformRoleCode::PlatformAdmin] as $role) {
            $admin = PlatformAdmin::factory()->create();
            $this->roleService->grant($admin, $role);

            $this->assertTrue($this->service->canManageOperations($admin)->allowed);
        }
    }

    public function test_security_auditor_and_read_only_auditor_cannot_manage_operations_even_though_they_can_view_it(): void
    {
        foreach ([PlatformRoleCode::SecurityAuditor, PlatformRoleCode::ReadOnlyAuditor] as $role) {
            $admin = PlatformAdmin::factory()->create();
            $this->roleService->grant($admin, $role);

            $this->assertTrue($this->service->canAccessOperations($admin)->allowed);
            $this->assertFalse(
                $this->service->canManageOperations($admin)->allowed,
                "{$role->value} must NOT be able to mutate Operations data — auditors observe, they do not act."
            );
        }
    }

    public function test_a_read_only_auditor_with_super_admin_also_held_still_cannot_mutate_via_the_blanket_rule(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SuperAdmin);
        $this->roleService->grant($admin, PlatformRoleCode::ReadOnlyAuditor);

        $this->assertTrue($this->service->canManageOperations($admin)->allowed);
        $this->assertFalse($this->service->canMutate($admin)->allowed);
    }
}
