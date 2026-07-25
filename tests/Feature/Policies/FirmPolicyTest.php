<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Enums\PlatformRoleCode;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Policies\FirmPolicy;
use App\Services\PlatformRoleService;
use App\Services\PlatformStaffAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FirmPolicyTest — Phase 1 FirmsVault Admin Control Center. Proves
 * FirmPolicy itself (a thin delegator), not merely
 * PlatformStaffAccessPolicyService (already covered directly by
 * PlatformStaffAccessPolicyServiceTest) — mirrors
 * FirmIntegrationPolicyTest's own "prove the policy class directly"
 * shape.
 */
class FirmPolicyTest extends TestCase
{
    use RefreshDatabase;

    private FirmPolicy $policy;

    private PlatformRoleService $roleService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roleService = new PlatformRoleService;
        $this->policy = new FirmPolicy(new PlatformStaffAccessPolicyService($this->roleService));
    }

    public function test_view_any_and_view_allow_a_platform_administration_role(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SupportAgent);

        $firm = Firm::factory()->create();

        $this->assertTrue($this->policy->viewAny($admin));
        $this->assertTrue($this->policy->view($admin, $firm));
    }

    public function test_view_any_and_view_deny_a_role_with_no_platform_administration_access(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SalesRep);

        $firm = Firm::factory()->create();

        $this->assertFalse($this->policy->viewAny($admin));
        $this->assertFalse($this->policy->view($admin, $firm));
    }

    public function test_update_requires_the_narrower_firm_management_ceiling(): void
    {
        $firm = Firm::factory()->create();

        $supportAgent = PlatformAdmin::factory()->create();
        $this->roleService->grant($supportAgent, PlatformRoleCode::SupportAgent);
        $this->assertTrue($this->policy->view($supportAgent, $firm), 'Sanity: support agent can view.');
        $this->assertFalse($this->policy->update($supportAgent, $firm), 'But support agent must not be able to manage/mutate a firm.');

        $platformAdmin = PlatformAdmin::factory()->create();
        $this->roleService->grant($platformAdmin, PlatformRoleCode::PlatformAdmin);
        $this->assertTrue($this->policy->update($platformAdmin, $firm));
    }

    public function test_an_admin_with_no_role_is_denied_every_gate(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $firm = Firm::factory()->create();

        $this->assertFalse($this->policy->viewAny($admin));
        $this->assertFalse($this->policy->view($admin, $firm));
        $this->assertFalse($this->policy->update($admin, $firm));
    }
}
