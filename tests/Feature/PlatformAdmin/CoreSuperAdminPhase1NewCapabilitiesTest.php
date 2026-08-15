<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\RevokePlatformAdminSessionsAction;
use App\Filament\Resources\FirmResource;
use App\Filament\Resources\FirmResource\Pages\EditFirm;
use App\Filament\Resources\FirmUserResource\Pages\ListFirmUsers;
use App\Filament\Resources\FirmUserResource\Pages\ViewFirmUser;
use App\Filament\Resources\PlatformAdministratorResource\Pages\ViewPlatformAdministrator;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CoreSuperAdminPhase1NewCapabilitiesTest — CORE SuperAdmin mission
 * (admin/core-superadmin-security), Phase 1. Proves the genuinely NEW
 * capabilities this mission added (as distinct from the existing tests
 * updated elsewhere to account for the new step-up requirement):
 * EditFirm's safe-metadata edit, InviteFirmUserAction, Firm User
 * suspend/reactivate/revoke-sessions, and the standalone
 * RevokePlatformAdminSessionsAction.
 */
final class CoreSuperAdminPhase1NewCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    // --- EditFirm ---

    public function test_a_platform_admin_can_edit_safe_firm_metadata_and_it_is_audited(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firm = Firm::factory()->create(['name' => 'Original Name', 'legal_name' => 'Original LLC']);

        Livewire::test(EditFirm::class, ['record' => $firm->getRouteKey()])
            ->fillForm(['name' => 'Renamed Firm', 'legal_name' => 'Renamed LLC'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $firm->fresh();
        $this->assertSame('Renamed Firm', $fresh->name);
        $this->assertSame('Renamed LLC', $fresh->legal_name);

        $auditRow = app(TenantContextService::class)->runWithFirmContext(
            $fresh,
            fn () => DB::table('security_events')->where('event_type', 'firm_metadata_updated')->where('actor_id', $admin->id)->first(),
        );
        $this->assertNotNull($auditRow, 'A Firm metadata edit must write an audit event.');
    }

    public function test_editing_a_firm_never_touches_system_managed_fields(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firm = Firm::factory()->create();
        $originalActivationStatus = $firm->activation_status;
        $originalCustomerType = $firm->customer_type;

        Livewire::test(EditFirm::class, ['record' => $firm->getRouteKey()])
            ->fillForm(['name' => 'A Different Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $firm->fresh();
        $this->assertSame($originalActivationStatus, $fresh->activation_status, 'Edit must never mutate the lifecycle state.');
        $this->assertSame($originalCustomerType, $fresh->customer_type, 'Edit must never mutate the customer-type classification.');
    }

    public function test_a_role_holder_without_firm_management_cannot_edit_a_firm(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin');

        $firm = Firm::factory()->create();

        $this->assertFalse(FirmResource::canEdit($firm));
        $this->get(FirmResource::getUrl('edit', ['record' => $firm]))->assertForbidden();
    }

    // --- InviteFirmUserAction ---

    public function test_a_platform_admin_can_invite_a_firm_user_and_it_is_audited(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firm = Firm::factory()->create();
        FirmLicense::factory()->for($firm)->create(['purchased_seats' => 5]);

        $test = Livewire::test(ListFirmUsers::class);
        $test->mountAction('inviteFirmUser');
        $test->setActionData([
            'firm_id' => $firm->id,
            'name' => 'New Firm User',
            'email' => 'newfirmuser@example.com',
            'role' => FirmUserRole::Paralegal->value,
        ]);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $invited = app(TenantContextService::class)->runWithFirmContext(
            $firm,
            fn () => FirmUser::query()->whereHas('user', fn ($q) => $q->where('email', 'newfirmuser@example.com'))->first(),
        );
        $this->assertNotNull($invited, 'The invitation must create a FirmUser row.');
        $this->assertSame(FirmUserStatus::Invited, $invited->status);

        $auditRow = app(TenantContextService::class)->runWithFirmContext(
            $firm,
            fn () => DB::table('security_events')->where('event_type', 'firm_user_invited_by_platform_admin')->where('actor_id', $admin->id)->first(),
        );
        $this->assertNotNull($auditRow);
    }

    public function test_a_role_holder_without_firm_management_cannot_invite_a_firm_user(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin');

        $firm = Firm::factory()->create();

        $test = Livewire::test(ListFirmUsers::class);
        $test->mountAction('inviteFirmUser');
        $test->setActionData([
            'firm_id' => $firm->id,
            'name' => 'Blocked Invite',
            'email' => 'blocked@example.com',
            'role' => FirmUserRole::Paralegal->value,
        ]);
        $test->callMountedAction();

        $exists = app(TenantContextService::class)->runWithFirmContext(
            $firm,
            fn () => FirmUser::query()->whereHas('user', fn ($q) => $q->where('email', 'blocked@example.com'))->exists(),
        );
        $this->assertFalse($exists, 'An unauthorized role must never be able to create a FirmUser.');
    }

    // --- Firm User suspend/reactivate/revoke sessions ---

    private function activeFirmUserWithUser(): FirmUser
    {
        $firm = Firm::factory()->create();

        return app(TenantContextService::class)->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::Paralegal)->create(['status' => FirmUserStatus::Active]),
        );
    }

    public function test_suspend_and_reactivate_toggle_status_and_are_audited(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firmUser = $this->activeFirmUserWithUser();
        $firm = Firm::query()->find($firmUser->firm_id);

        $test = Livewire::test(ViewFirmUser::class, ['firmUuid' => $firm->uuid, 'firmUserUuid' => $firmUser->uuid]);
        $test->mountAction('toggleFirmUserStatus');
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $suspended = app(TenantContextService::class)->runWithFirmContext($firm, fn () => FirmUser::query()->find($firmUser->id));
        $this->assertSame(FirmUserStatus::Suspended, $suspended->status);

        $suspendAudit = app(TenantContextService::class)->runWithFirmContext(
            $firm,
            fn () => DB::table('security_events')->where('event_type', 'firm_user_suspended_by_platform_admin')->where('actor_id', $admin->id)->first(),
        );
        $this->assertNotNull($suspendAudit);

        $test2 = Livewire::test(ViewFirmUser::class, ['firmUuid' => $firm->uuid, 'firmUserUuid' => $firmUser->uuid]);
        $test2->mountAction('toggleFirmUserStatus');
        $test2->callMountedAction();
        $test2->assertHasNoActionErrors();

        $reactivated = app(TenantContextService::class)->runWithFirmContext($firm, fn () => FirmUser::query()->find($firmUser->id));
        $this->assertSame(FirmUserStatus::Active, $reactivated->status);
    }

    public function test_revoke_firm_user_sessions_is_audited(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firmUser = $this->activeFirmUserWithUser();
        $firm = Firm::query()->find($firmUser->firm_id);

        $test = Livewire::test(ViewFirmUser::class, ['firmUuid' => $firm->uuid, 'firmUserUuid' => $firmUser->uuid]);
        $test->mountAction('revokeFirmUserSessions');
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $auditRow = app(TenantContextService::class)->runWithFirmContext(
            $firm,
            fn () => DB::table('security_events')->where('event_type', 'firm_user_sessions_revoked_by_platform_admin')->where('actor_id', $admin->id)->first(),
        );
        $this->assertNotNull($auditRow);
    }

    // --- RevokePlatformAdminSessionsAction ---

    public function test_revoke_platform_admin_sessions_requires_step_up_and_is_audited(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $target = PlatformAdmin::factory()->create(['is_active' => true]);

        $test = Livewire::test(ViewPlatformAdministrator::class, ['record' => $target->uuid]);
        $test->mountAction(RevokePlatformAdminSessionsAction::getDefaultName());
        $test->callMountedAction();
        $test->assertHasActionErrors(['stepUpCurrentPassword']);

        $test2 = Livewire::test(ViewPlatformAdministrator::class, ['record' => $target->uuid]);
        $test2->mountAction(RevokePlatformAdminSessionsAction::getDefaultName());
        $test2->setActionData(['stepUpCurrentPassword' => 'password']);
        $test2->callMountedAction();
        $test2->assertHasNoActionErrors();

        $auditRow = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'platform_admin_sessions_revoked')->where('actor_id', $admin->id)->first(),
        );
        $this->assertNotNull($auditRow);
    }
}
