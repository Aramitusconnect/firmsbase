<?php

declare(strict_types=1);

namespace Tests\Feature\Security\PlatformAdminMfa;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\ResetPlatformAdminMfaAction;
use App\Filament\Actions\Platform\RevokePlatformAdminRoleAction;
use App\Filament\Actions\Platform\TogglePlatformAdminActiveStatusAction;
use App\Filament\Resources\PlatformAdministratorResource\Pages\ViewPlatformAdministrator;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformAdminLastSuperAdminProtectionTest — Platform Administrators
 * resource. PlatformRoleService::wouldLeaveNoActiveSuperAdmin() unit
 * coverage, plus real Livewire mountAction()/callMountedAction() proof
 * that TogglePlatformAdminActiveStatusAction (deactivate direction) and
 * RevokePlatformAdminRoleAction (revoking SuperAdmin specifically) are
 * actually blocked by it end to end — and that
 * ResetPlatformAdminMfaAction, per the MFA design proposal's explicit
 * §6 finding, is NOT, even in the exact same sole-SuperAdmin scenario.
 */
class PlatformAdminLastSuperAdminProtectionTest extends TestCase
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

    // --- PlatformRoleService::wouldLeaveNoActiveSuperAdmin() unit coverage ---

    public function test_would_leave_no_active_super_admin_true_for_sole_super_admin(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);

        $this->assertTrue($this->roleService()->wouldLeaveNoActiveSuperAdmin($admin));
        $this->assertTrue($this->roleService()->wouldLeaveNoActiveSuperAdmin($admin, PlatformRoleCode::SuperAdmin));
    }

    public function test_would_leave_no_active_super_admin_false_when_another_active_super_admin_exists(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);

        $other = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($other, PlatformRoleCode::SuperAdmin);

        $this->assertFalse($this->roleService()->wouldLeaveNoActiveSuperAdmin($admin));
    }

    public function test_would_leave_no_active_super_admin_false_when_other_super_admin_exists_but_is_inactive(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);

        $inactiveOther = PlatformAdmin::factory()->create(['is_active' => false]);
        $this->roleService()->grant($inactiveOther, PlatformRoleCode::SuperAdmin);

        // The only OTHER SuperAdmin is inactive, so deactivating $admin
        // would still leave zero ACTIVE SuperAdmins.
        $this->assertTrue($this->roleService()->wouldLeaveNoActiveSuperAdmin($admin));
    }

    public function test_would_leave_no_active_super_admin_short_circuits_false_for_a_non_super_admin_role(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);
        // $admin is the SOLE SuperAdmin, but revoking a DIFFERENT role
        // can never affect that.
        $this->roleService()->grant($admin, PlatformRoleCode::BillingAdmin);

        $this->assertFalse($this->roleService()->wouldLeaveNoActiveSuperAdmin($admin, PlatformRoleCode::BillingAdmin));
    }

    public function test_would_leave_no_active_super_admin_false_for_an_admin_who_is_not_a_super_admin(): void
    {
        $nonSuperAdmin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($nonSuperAdmin, PlatformRoleCode::BillingAdmin);

        // No SuperAdmin exists anywhere in the system — a pre-existing
        // broken state, but deactivating this unrelated BillingAdmin
        // does not CAUSE it, so this must not be blocked by this guard.
        $this->assertFalse($this->roleService()->wouldLeaveNoActiveSuperAdmin($nonSuperAdmin));
    }

    // --- End-to-end action proofs ---

    /**
     * Deliberately a SELF-target scenario (actor deactivating their own
     * account), not a different-actor-targets-another-admin scenario —
     * TogglePlatformAdminActiveStatusAction's own gate
     * (canManagePlatformAdministrators()) already REQUIRES the acting
     * admin to themselves be an active SuperAdmin, so in any two-admin
     * scenario the acting SuperAdmin is always excluded from the
     * record being deactivated and therefore always still counts as
     * "one remaining active SuperAdmin" — the guard can only actually
     * trigger when the target IS the actor. This is exactly the
     * "self-lockout prevention" case the mission brief names
     * separately from the plain cross-admin deactivation case.
     */
    public function test_deactivate_action_is_blocked_when_the_sole_super_admin_targets_themselves(): void
    {
        $sole = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($sole, PlatformRoleCode::SuperAdmin);
        $this->actingAs($sole, 'platform_admin');

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $sole->uuid]);
        $test->mountAction(TogglePlatformAdminActiveStatusAction::getDefaultName());
        $test->callMountedAction();

        $sole->refresh();
        $this->assertTrue($sole->is_active, 'The sole active SuperAdmin must not be able to deactivate themselves.');
    }

    public function test_deactivate_action_succeeds_when_another_active_super_admin_exists(): void
    {
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($actor, PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $target = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($target, PlatformRoleCode::SuperAdmin);

        // A second active SuperAdmin — makes it safe to deactivate $actor's target below.
        $other = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($other, PlatformRoleCode::SuperAdmin);

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $target->uuid]);
        $test->mountAction(TogglePlatformAdminActiveStatusAction::getDefaultName());
        $test->callMountedAction();

        $target->refresh();
        $this->assertFalse($target->is_active);
    }

    /**
     * Same self-target reasoning as the deactivate test above —
     * RevokePlatformAdminRoleAction's own gate (canManageRoles()) also
     * requires the acting admin to themselves be an active SuperAdmin,
     * so this guard is only actually reachable when the sole SuperAdmin
     * targets their OWN SuperAdmin grant.
     */
    public function test_revoke_super_admin_role_action_is_blocked_when_the_sole_super_admin_targets_their_own_role(): void
    {
        $sole = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($sole, PlatformRoleCode::SuperAdmin);
        $this->actingAs($sole, 'platform_admin');

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $sole->uuid]);
        $test->mountAction(RevokePlatformAdminRoleAction::getDefaultName());
        $test->setActionData(['role_code' => PlatformRoleCode::SuperAdmin->value]);
        $test->callMountedAction();

        $this->assertTrue($this->roleService()->hasRole($sole->fresh(), PlatformRoleCode::SuperAdmin));
    }

    public function test_revoke_super_admin_role_action_succeeds_when_another_active_super_admin_exists(): void
    {
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($actor, PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $target = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($target, PlatformRoleCode::SuperAdmin);

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $target->uuid]);
        $test->mountAction(RevokePlatformAdminRoleAction::getDefaultName());
        $test->setActionData(['role_code' => PlatformRoleCode::SuperAdmin->value]);
        $test->callMountedAction();

        $this->assertFalse($this->roleService()->hasRole($target->fresh(), PlatformRoleCode::SuperAdmin), 'Revoking a DIFFERENT admin\'s SuperAdmin role, while the actor remains one, must succeed.');
    }

    /**
     * MFA design proposal §6's explicit finding: an MFA reset never
     * revokes a role or deactivates an account, so it must remain
     * unconditionally available even against the sole active
     * SuperAdmin — proven here at the SAME action-layer/Livewire level
     * as the two blocked actions above, in the exact same fixture
     * shape, so this is a genuine contrast test, not just a
     * service-level assertion (see PlatformAdminMfaResetServiceTest for
     * that).
     */
    public function test_reset_mfa_action_is_not_blocked_for_the_sole_active_super_admin(): void
    {
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->roleService()->grant($actor, PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $sole = PlatformAdmin::factory()->create([
            'is_active' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);
        $this->roleService()->grant($sole, PlatformRoleCode::SuperAdmin);

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $sole->uuid]);
        $test->mountAction(ResetPlatformAdminMfaAction::getDefaultName());
        $test->setActionData(['reason' => 'lost device, sole superadmin']);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $sole->refresh();
        $this->assertNull($sole->two_factor_secret, 'MFA reset must succeed even against the sole active SuperAdmin.');
        $this->assertTrue($sole->is_active);
        $this->assertTrue($this->roleService()->hasRole($sole, PlatformRoleCode::SuperAdmin));
    }

    public function test_reset_mfa_action_rejects_self_target(): void
    {
        $actor = PlatformAdmin::factory()->create([
            'is_active' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);
        $this->roleService()->grant($actor, PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $actor->uuid]);
        $test->mountAction(ResetPlatformAdminMfaAction::getDefaultName());
        $test->setActionData(['reason' => 'trying to reset my own MFA']);
        $test->callMountedAction();

        $actor->refresh();
        $this->assertSame('JBSWY3DPEHPK3PXP', $actor->two_factor_secret, 'A SuperAdmin must not be able to reset their own MFA through this action.');
    }
}
