<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Enums\PlatformRoleCode;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Policies\FirmUserPolicy;
use App\Services\PlatformRoleService;
use App\Services\PlatformStaffAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirmUserPolicyTest extends TestCase
{
    use RefreshDatabase;

    private FirmUserPolicy $policy;

    private PlatformRoleService $roleService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roleService = new PlatformRoleService;
        $this->policy = new FirmUserPolicy(new PlatformStaffAccessPolicyService($this->roleService));
    }

    public function test_view_any_and_view_allow_a_platform_administration_role(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::ImplementationSpecialist);

        $firm = Firm::factory()->create();
        $firmUser = $this->createWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create());

        $this->assertTrue($this->policy->viewAny($admin));
        $this->assertTrue($this->policy->view($admin, $firmUser));
    }

    public function test_view_any_and_view_deny_a_sales_role(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->roleService->grant($admin, PlatformRoleCode::SalesManager);

        $firm = Firm::factory()->create();
        $firmUser = $this->createWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create());

        $this->assertFalse($this->policy->viewAny($admin));
        $this->assertFalse($this->policy->view($admin, $firmUser));
    }
}
