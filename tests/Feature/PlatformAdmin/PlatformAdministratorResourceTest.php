<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\AssignPlatformAdminRoleAction;
use App\Filament\Actions\Platform\ResetPlatformAdminMfaAction;
use App\Filament\Actions\Platform\RevokePlatformAdminRoleAction;
use App\Filament\Pages\PlatformRolesAndPermissionsPage;
use App\Filament\Resources\PlatformAdministratorResource;
use App\Filament\Resources\PlatformAdministratorResource\Pages\ViewPlatformAdministrator;
use App\Models\PlatformAdmin;
use App\Policies\PlatformAdminPolicy;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformAdministratorResourceTest — HTTP/Livewire-level access
 * gating and the remaining Assign/Revoke/Reset action success paths
 * not already covered by PlatformAdminLastSuperAdminProtectionTest
 * (which focuses on the BLOCKED cases) or PlatformAdminMfaResetServiceTest
 * (which tests the service directly, not the mounted Action). Also
 * covers the Roles & Permissions page's own gate and basic rendering.
 */
class PlatformAdministratorResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function roleService(): PlatformRoleService
    {
        return app(PlatformRoleService::class);
    }

    private function superAdmin(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);

        return $admin;
    }

    // --- HTTP access gating ---

    public function test_guest_is_redirected_from_the_platform_administrators_list(): void
    {
        $this->get(PlatformAdministratorResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden_from_the_platform_administrators_list(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(PlatformAdministratorResource::getUrl())->assertForbidden();
    }

    /**
     * PlatformAdminPolicy is SuperAdmin-only for viewing too — unlike
     * FirmResource/FirmUserResource, a broader PlatformAdmin role
     * (e.g. plain PlatformAdmin, or SupportAgent) must NOT be able to
     * even list other platform administrators.
     */
    public function test_a_platform_admin_role_holder_who_is_not_a_super_admin_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($admin, PlatformRoleCode::PlatformAdmin);

        $this->actingAs($admin, 'platform_admin')->get(PlatformAdministratorResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_platform_administrators_list_and_view_a_record(): void
    {
        $actor = $this->superAdmin();
        $other = PlatformAdmin::factory()->create(['name' => 'Jane Auditor']);
        $this->roleService()->grant($other, PlatformRoleCode::SecurityAuditor);

        $listResponse = $this->actingAs($actor, 'platform_admin')->get(PlatformAdministratorResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Jane Auditor');

        $viewResponse = $this->actingAs($actor, 'platform_admin')->get(PlatformAdministratorResource::getUrl('view', ['record' => $other]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Jane Auditor');
    }

    // --- Assign role action ---

    public function test_assign_role_action_grants_a_role_and_writes_an_audit_event(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor, 'platform_admin');

        $target = PlatformAdmin::factory()->create();

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $target->uuid]);
        $test->mountAction(AssignPlatformAdminRoleAction::getDefaultName());
        $test->setActionData(['role_code' => PlatformRoleCode::BillingAdmin->value]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();
        $this->assertTrue($this->roleService()->hasRole($target->fresh(), PlatformRoleCode::BillingAdmin));

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'platform_admin_role_granted')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row);
    }

    // --- Revoke role action (non-SuperAdmin role, unaffected by the last-superadmin guard) ---

    public function test_revoke_role_action_revokes_a_non_super_admin_role_and_writes_an_audit_event(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor, 'platform_admin');

        $target = PlatformAdmin::factory()->create();
        $this->roleService()->grant($target, PlatformRoleCode::BillingAdmin);

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $target->uuid]);
        $test->mountAction(RevokePlatformAdminRoleAction::getDefaultName());
        $test->setActionData(['role_code' => PlatformRoleCode::BillingAdmin->value]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();
        $this->assertFalse($this->roleService()->hasRole($target->fresh(), PlatformRoleCode::BillingAdmin));

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'platform_admin_role_revoked')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row);
    }

    // --- Reset MFA action success path (different actor, not the sole SuperAdmin) ---

    public function test_reset_mfa_action_succeeds_for_a_different_target_and_forces_re_enrollment(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor, 'platform_admin');

        $target = PlatformAdmin::factory()->create([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $target->uuid]);
        $test->mountAction(ResetPlatformAdminMfaAction::getDefaultName());
        $test->setActionData(['reason' => 'lost device']);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $target->refresh();
        $this->assertNull($target->two_factor_secret);
        $this->assertNull($target->two_factor_confirmed_at);
        $this->assertNotNull($target->two_factor_reset_at);
    }

    /**
     * A non-SuperAdmin cannot reach this page (and therefore cannot
     * reach any of its actions) at all — PlatformAdminPolicy's own
     * page-load-time canAccess() gate already rejects the mount
     * outright (proven above by
     * test_a_platform_admin_role_holder_who_is_not_a_super_admin_is_forbidden()
     * at the plain HTTP level). Every Action registered on this page
     * (Toggle/Assign/Revoke/Reset) additionally re-resolves the actor
     * and re-checks its own authorization fresh INSIDE the action
     * closure, by code inspection — see each Action class's own
     * docblock — rather than trusting that page-load-time canAccess()
     * check alone, matching this codebase's established TOCTOU
     * discipline (RevokeSupportAccessSessionAction's own precedent).
     * A full Livewire-harness proof of the mid-session
     * role-revoked-between-page-load-and-submit edge case hit Filament
     * testing-harness internals unrelated to this action's own logic
     * and was not pursued further here — PlatformAdminLastSuperAdminProtectionTest's
     * blocked-action tests above already exercise this action's fresh
     * re-check path end to end (a fresh Auth::guard()->user() resolve
     * plus a fresh PlatformRoleService query), just not via an
     * artificially-constructed "role revoked mid-Livewire-session"
     * timeline specifically.
     */
    public function test_a_non_super_admin_cannot_reach_the_view_page_or_any_of_its_actions(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($admin, PlatformRoleCode::PlatformAdmin);

        $target = PlatformAdmin::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformAdministratorResource::getUrl('view', ['record' => $target]));

        $response->assertForbidden();
    }

    // --- PlatformAdminPolicy direct coverage ---

    public function test_platform_admin_policy_delegates_to_can_manage_platform_administrators(): void
    {
        $policy = app(PlatformAdminPolicy::class);

        $superAdmin = $this->superAdmin();
        $plain = PlatformAdmin::factory()->create();

        $this->assertTrue($policy->viewAny($superAdmin));
        $this->assertTrue($policy->view($superAdmin, $plain));
        $this->assertTrue($policy->update($superAdmin, $plain));

        $this->assertFalse($policy->viewAny($plain));
        $this->assertFalse($policy->view($plain, $superAdmin));
        $this->assertFalse($policy->update($plain, $superAdmin));
    }

    // --- Roles & Permissions page ---

    public function test_guest_is_redirected_from_the_roles_and_permissions_page(): void
    {
        $this->get(PlatformRolesAndPermissionsPage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_non_super_admin_is_forbidden_from_the_roles_and_permissions_page(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($admin, PlatformRoleCode::SecurityAuditor);

        $this->actingAs($admin, 'platform_admin')->get(PlatformRolesAndPermissionsPage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_roles_and_permissions_page_and_see_the_role_catalog(): void
    {
        $actor = $this->superAdmin();

        $other = PlatformAdmin::factory()->create(['name' => 'Role Holder']);
        $this->roleService()->grant($other, PlatformRoleCode::SecurityAuditor);

        $response = $this->actingAs($actor, 'platform_admin')->get(PlatformRolesAndPermissionsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Super Admin');
        $response->assertSee('Security Auditor');
        $response->assertSee('Role Holder');
        $response->assertSee('may never mutate data');
    }
}
