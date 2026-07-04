<?php

namespace Tests\Feature\PlatformStaff;

use App\Enums\PlatformRoleCode;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformRoleServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformRoleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlatformRoleService();
    }

    public function test_grant_creates_an_active_role_assignment(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $role = $this->service->grant($admin, PlatformRoleCode::SalesRep);

        $this->assertSame(PlatformRoleCode::SalesRep, $role->role_code);
        $this->assertTrue($this->service->hasRole($admin, PlatformRoleCode::SalesRep));
    }

    public function test_granting_an_already_active_role_is_idempotent(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $first = $this->service->grant($admin, PlatformRoleCode::BillingAdmin);
        $second = $this->service->grant($admin, PlatformRoleCode::BillingAdmin);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, \App\Models\PlatformRole::query()->where('platform_admin_id', $admin->id)->count());
    }

    public function test_revoke_removes_active_status_and_allows_regrant(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->service->grant($admin, PlatformRoleCode::SupportAgent);
        $this->service->revoke($admin, PlatformRoleCode::SupportAgent);

        $this->assertFalse($this->service->hasRole($admin, PlatformRoleCode::SupportAgent));

        $regrant = $this->service->grant($admin, PlatformRoleCode::SupportAgent);

        $this->assertTrue($this->service->hasRole($admin, PlatformRoleCode::SupportAgent));
        $this->assertSame(2, \App\Models\PlatformRole::query()->where('platform_admin_id', $admin->id)->count());
        $this->assertNotNull($regrant->id);
    }

    public function test_an_admin_may_hold_multiple_active_roles(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->service->grant($admin, PlatformRoleCode::SalesManager);
        $this->service->grant($admin, PlatformRoleCode::SalesRep);

        $roles = $this->service->activeRolesFor($admin);

        $this->assertCount(2, $roles);
        $this->assertContains(PlatformRoleCode::SalesManager, $roles);
        $this->assertContains(PlatformRoleCode::SalesRep, $roles);
    }
}
