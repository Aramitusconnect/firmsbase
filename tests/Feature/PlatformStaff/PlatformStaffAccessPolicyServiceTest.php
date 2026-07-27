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
        $this->assertTrue($this->service->canManagePlatformBilling($admin)->allowed);
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

    // ------------------------------------------------------------
    // Phase 3 FirmsVault Admin Control Center additions
    // ("Billing and Commercial Administration")
    // ------------------------------------------------------------

    public function test_super_admin_and_platform_admin_can_manage_platform_billing(): void
    {
        $superAdmin = PlatformAdmin::factory()->create();
        $this->roleService->grant($superAdmin, PlatformRoleCode::SuperAdmin);

        $platformAdmin = PlatformAdmin::factory()->create();
        $this->roleService->grant($platformAdmin, PlatformRoleCode::PlatformAdmin);

        foreach ([$superAdmin, $platformAdmin] as $admin) {
            $this->assertTrue($this->service->canManagePlatformBilling($admin)->allowed);
        }
    }

    public function test_billing_admin_can_read_platform_billing_but_not_manage_it(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::BillingAdmin);

        $this->assertTrue(
            $this->service->canAccessPlatformBilling($admin)->allowed,
            'canAccessPlatformBilling() (read) must stay unweakened by the new manage gate.'
        );
        $this->assertFalse(
            $this->service->canManagePlatformBilling($admin)->allowed,
            'BillingAdmin may view platform billing but must not be able to mutate it — narrowed to the same SuperAdmin/PlatformAdmin ceiling every other manage gate in this class uses.'
        );
    }

    public function test_other_roles_cannot_manage_or_read_platform_billing(): void
    {
        $supportAgent = PlatformAdmin::factory()->create();
        $this->roleService->grant($supportAgent, PlatformRoleCode::SupportAgent);

        $salesRep = PlatformAdmin::factory()->create();
        $this->roleService->grant($salesRep, PlatformRoleCode::SalesRep);

        foreach ([$supportAgent, $salesRep] as $admin) {
            $this->assertFalse($this->service->canAccessPlatformBilling($admin)->allowed);
            $this->assertFalse($this->service->canManagePlatformBilling($admin)->allowed);
        }
    }

    public function test_a_platform_admin_with_no_role_is_denied_platform_billing_management(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->assertFalse($this->service->canManagePlatformBilling($admin)->allowed);
        $this->assertNotNull($this->service->canManagePlatformBilling($admin)->reason);
    }

    // ------------------------------------------------------------
    // Phase 4 FirmsVault Admin Control Center additions ("Support" +
    // "Configuration" categories).
    // ------------------------------------------------------------

    public function test_support_agent_and_implementation_specialist_pass_integration_oversight_but_not_support_access_management(): void
    {
        $supportAgent = PlatformAdmin::factory()->create();
        $this->roleService->grant($supportAgent, PlatformRoleCode::SupportAgent);

        $implementationSpecialist = PlatformAdmin::factory()->create();
        $this->roleService->grant($implementationSpecialist, PlatformRoleCode::ImplementationSpecialist);

        foreach ([$supportAgent, $implementationSpecialist] as $admin) {
            $this->assertTrue(
                $this->service->canAccessIntegrationOversight($admin)->allowed,
                'Support Cases/Approved Support Sessions reads deliberately reuse this existing gate.'
            );
            $this->assertFalse($this->service->canManageSupportAccess($admin)->allowed);
        }
    }

    public function test_super_admin_and_platform_admin_can_manage_support_access(): void
    {
        foreach ([PlatformRoleCode::SuperAdmin, PlatformRoleCode::PlatformAdmin] as $role) {
            $admin = PlatformAdmin::factory()->create();
            $this->roleService->grant($admin, $role);

            $this->assertTrue($this->service->canManageSupportAccess($admin)->allowed);
        }
    }

    public function test_implementation_specialist_and_billing_admin_can_read_entitlement_overrides_but_not_manage_them(): void
    {
        $implementationSpecialist = PlatformAdmin::factory()->create();
        $this->roleService->grant($implementationSpecialist, PlatformRoleCode::ImplementationSpecialist);

        $billingAdmin = PlatformAdmin::factory()->create();
        $this->roleService->grant($billingAdmin, PlatformRoleCode::BillingAdmin);

        foreach ([$implementationSpecialist, $billingAdmin] as $admin) {
            $this->assertTrue($this->service->canAccessEntitlementOverrides($admin)->allowed);
            $this->assertFalse($this->service->canManageEntitlementOverrides($admin)->allowed);
        }
    }

    public function test_support_agent_cannot_access_entitlement_overrides(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SupportAgent);

        $this->assertFalse($this->service->canAccessEntitlementOverrides($admin)->allowed);
        $this->assertFalse($this->service->canManageEntitlementOverrides($admin)->allowed);
    }

    public function test_security_auditor_can_read_ai_policy_settings_but_not_manage_them(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SecurityAuditor);

        $this->assertTrue($this->service->canAccessAiPolicySettings($admin)->allowed);
        $this->assertFalse($this->service->canManageAiPolicySettings($admin)->allowed);
    }

    public function test_billing_admin_cannot_access_ai_policy_settings(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::BillingAdmin);

        $this->assertFalse($this->service->canAccessAiPolicySettings($admin)->allowed);
    }

    public function test_implementation_specialist_can_read_notification_templates_but_not_manage_them(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::ImplementationSpecialist);

        $this->assertTrue($this->service->canAccessNotificationTemplates($admin)->allowed);
        $this->assertFalse($this->service->canManageNotificationTemplates($admin)->allowed);
    }

    public function test_billing_admin_and_support_agent_cannot_access_notification_templates(): void
    {
        $billingAdmin = PlatformAdmin::factory()->create();
        $this->roleService->grant($billingAdmin, PlatformRoleCode::BillingAdmin);

        $supportAgent = PlatformAdmin::factory()->create();
        $this->roleService->grant($supportAgent, PlatformRoleCode::SupportAgent);

        foreach ([$billingAdmin, $supportAgent] as $admin) {
            $this->assertFalse($this->service->canAccessNotificationTemplates($admin)->allowed);
        }
    }

    public function test_super_admin_can_manage_every_phase_4_support_and_configuration_gate(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SuperAdmin);

        $this->assertTrue($this->service->canManageSupportAccess($admin)->allowed);
        $this->assertTrue($this->service->canAccessEntitlementOverrides($admin)->allowed);
        $this->assertTrue($this->service->canManageEntitlementOverrides($admin)->allowed);
        $this->assertTrue($this->service->canAccessAiPolicySettings($admin)->allowed);
        $this->assertTrue($this->service->canManageAiPolicySettings($admin)->allowed);
        $this->assertTrue($this->service->canAccessNotificationTemplates($admin)->allowed);
        $this->assertTrue($this->service->canManageNotificationTemplates($admin)->allowed);
    }

    public function test_a_platform_admin_with_no_role_is_denied_every_phase_4_support_and_configuration_gate(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->assertFalse($this->service->canManageSupportAccess($admin)->allowed);
        $this->assertFalse($this->service->canAccessEntitlementOverrides($admin)->allowed);
        $this->assertFalse($this->service->canManageEntitlementOverrides($admin)->allowed);
        $this->assertFalse($this->service->canAccessAiPolicySettings($admin)->allowed);
        $this->assertFalse($this->service->canManageAiPolicySettings($admin)->allowed);
        $this->assertFalse($this->service->canAccessNotificationTemplates($admin)->allowed);
        $this->assertFalse($this->service->canManageNotificationTemplates($admin)->allowed);
    }

    // ------------------------------------------------------------
    // Phase 4 FirmsVault Admin Control Center additions — GOVERNANCE
    // category (Audit Logs, Retention, Legal Holds, Data Exports,
    // Deletion Requests). Landed concurrently in this same shared
    // worktree alongside the "Support" + "Configuration" block above —
    // no gate-name collisions (verified directly against
    // PlatformStaffAccessPolicyService.php before adding these).
    // ------------------------------------------------------------

    public function test_super_admin_platform_admin_security_auditor_and_read_only_auditor_can_access_governance(): void
    {
        foreach ([
            PlatformRoleCode::SuperAdmin,
            PlatformRoleCode::PlatformAdmin,
            PlatformRoleCode::SecurityAuditor,
            PlatformRoleCode::ReadOnlyAuditor,
        ] as $role) {
            $admin = PlatformAdmin::factory()->create();
            $this->roleService->grant($admin, $role);

            $this->assertTrue($this->service->canAccessGovernance($admin)->allowed, "{$role->value} must be able to access governance data.");
        }
    }

    public function test_billing_admin_support_agent_and_sales_roles_cannot_access_governance(): void
    {
        foreach ([
            PlatformRoleCode::BillingAdmin,
            PlatformRoleCode::SupportAgent,
            PlatformRoleCode::ImplementationSpecialist,
            PlatformRoleCode::SalesManager,
            PlatformRoleCode::SalesRep,
        ] as $role) {
            $admin = PlatformAdmin::factory()->create();
            $this->roleService->grant($admin, $role);

            $this->assertFalse($this->service->canAccessGovernance($admin)->allowed, "{$role->value} must NOT be able to access governance data.");
        }
    }

    public function test_super_admin_and_platform_admin_can_manage_legal_holds(): void
    {
        foreach ([PlatformRoleCode::SuperAdmin, PlatformRoleCode::PlatformAdmin] as $role) {
            $admin = PlatformAdmin::factory()->create();
            $this->roleService->grant($admin, $role);

            $this->assertTrue($this->service->canManageLegalHolds($admin)->allowed);
        }
    }

    public function test_security_auditor_and_read_only_auditor_can_view_but_not_manage_legal_holds(): void
    {
        foreach ([PlatformRoleCode::SecurityAuditor, PlatformRoleCode::ReadOnlyAuditor] as $role) {
            $admin = PlatformAdmin::factory()->create();
            $this->roleService->grant($admin, $role);

            $this->assertTrue(
                $this->service->canAccessGovernance($admin)->allowed,
                "{$role->value} can view governance data (including legal holds)."
            );
            $this->assertFalse(
                $this->service->canManageLegalHolds($admin)->allowed,
                "{$role->value} must NOT be able to place/release a legal hold — narrowed to SuperAdmin/PlatformAdmin only."
            );
        }
    }

    public function test_super_admin_and_platform_admin_can_manage_data_exports(): void
    {
        foreach ([PlatformRoleCode::SuperAdmin, PlatformRoleCode::PlatformAdmin] as $role) {
            $admin = PlatformAdmin::factory()->create();
            $this->roleService->grant($admin, $role);

            $this->assertTrue($this->service->canManageDataExports($admin)->allowed);
        }
    }

    public function test_read_only_auditor_cannot_manage_data_exports(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::ReadOnlyAuditor);

        $this->assertFalse($this->service->canManageDataExports($admin)->allowed);
    }

    public function test_super_admin_and_platform_admin_can_manage_deletion_governance(): void
    {
        foreach ([PlatformRoleCode::SuperAdmin, PlatformRoleCode::PlatformAdmin] as $role) {
            $admin = PlatformAdmin::factory()->create();
            $this->roleService->grant($admin, $role);

            $this->assertTrue($this->service->canManageDeletionGovernance($admin)->allowed);
        }
    }

    public function test_security_auditor_and_read_only_auditor_cannot_manage_deletion_governance_even_though_they_can_view_it(): void
    {
        foreach ([PlatformRoleCode::SecurityAuditor, PlatformRoleCode::ReadOnlyAuditor] as $role) {
            $admin = PlatformAdmin::factory()->create();
            $this->roleService->grant($admin, $role);

            $this->assertTrue($this->service->canAccessGovernance($admin)->allowed);
            $this->assertFalse(
                $this->service->canManageDeletionGovernance($admin)->allowed,
                "{$role->value} must NOT be able to approve/deny a production data deletion — the most sensitive Governance action."
            );
        }
    }

    public function test_a_read_only_auditor_with_super_admin_also_held_still_cannot_mutate_governance_data(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SuperAdmin);
        $this->roleService->grant($admin, PlatformRoleCode::ReadOnlyAuditor);

        // The narrow "manage" gates themselves allow SuperAdmin, but the
        // blanket canMutate() rule (checked separately, at the Filament
        // Action layer) is what actually blocks a ReadOnlyAuditor —
        // this proves that blanket rule is unaffected by these new
        // gates.
        $this->assertTrue($this->service->canManageLegalHolds($admin)->allowed);
        $this->assertFalse($this->service->canMutate($admin)->allowed);
    }

    public function test_a_platform_admin_with_no_role_is_denied_every_new_governance_gate(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->assertFalse($this->service->canAccessGovernance($admin)->allowed);
        $this->assertFalse($this->service->canManageLegalHolds($admin)->allowed);
        $this->assertFalse($this->service->canManageDataExports($admin)->allowed);
        $this->assertFalse($this->service->canManageDeletionGovernance($admin)->allowed);
    }
}
