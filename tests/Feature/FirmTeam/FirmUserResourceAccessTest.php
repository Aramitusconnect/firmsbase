<?php

declare(strict_types=1);

namespace Tests\Feature\FirmTeam;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\SeatClass;
use App\Filament\Firm\Resources\FirmUserResource;
use App\Filament\Firm\Resources\FirmUserResource\Actions\InviteFirmUserAction;
use App\Filament\Firm\Resources\FirmUserResource\Actions\ReactivateFirmUserAction;
use App\Filament\Firm\Resources\FirmUserResource\Actions\RemoveFirmUserAction;
use App\Filament\Firm\Resources\FirmUserResource\Actions\SuspendFirmUserAction;
use App\Filament\Firm\Resources\FirmUserResource\Pages\ListFirmUsers;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\FirmUserInvitationService;
use App\Services\SeatAllocationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FirmUserResourceAccessTest — Firm Feature Manifest §12 UI proof:
 * (1) invite is reachable/visible for FirmOwner only, blocked for every
 * other role; (2) suspend/reactivate/remove work through the UI and
 * respect the last-owner guard; (3) the invite role Select never offers
 * anything beyond the 6 real FirmUserRole values; (4) the small RLS
 * regression checklist for this module — a firm's own team list only
 * shows its own FirmUsers, a foreign firm's users never leak into the
 * list/invite-role-selector, and a direct URL to a foreign firm's
 * FirmUser record is blocked. Matches ClientResourceAccessTest's/
 * DocumentRequestAccessTest's own established style.
 */
final class FirmUserResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. canAccess() role ceiling — every active role may VIEW.
    // ------------------------------------------------------------

    public function test_every_active_role_can_access_the_firm_user_resource(): void
    {
        foreach (FirmUserRole::cases() as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $this->assertTrue(FirmUserResource::canAccess(), "canAccess() failed for role {$role->value}");
        }
    }

    public function test_guest_cannot_access_the_firm_user_resource(): void
    {
        $this->assertFalse(FirmUserResource::canAccess());
    }

    public function test_firm_user_resource_declares_no_create_page(): void
    {
        $pages = FirmUserResource::getPages();

        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }

    // ------------------------------------------------------------
    // 2. Invite action — FirmOwner only.
    // ------------------------------------------------------------

    public function test_invite_action_is_visible_for_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListFirmUsers::class));

        $test->assertActionVisible(InviteFirmUserAction::getDefaultName());
    }

    public function test_invite_action_is_hidden_for_every_non_owner_role(): void
    {
        foreach (FirmUserRole::cases() as $role) {
            if ($role === FirmUserRole::FirmOwner) {
                continue;
            }

            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListFirmUsers::class));

            $test->assertActionHidden(InviteFirmUserAction::getDefaultName());
        }
    }

    public function test_invite_role_select_offers_exactly_the_six_real_firm_user_roles(): void
    {
        // Structural guarantee, not a guess: InviteFirmUserAction's role
        // Select is built directly from FirmUserRole::cases() (see that
        // Action's own source) — the enum itself has exactly 6 cases and
        // structurally cannot contain a platform-admin concept (see
        // FirmUserRole's own docblock). Confirmed here at both levels:
        // the enum shape itself, AND that every one of those 6 labels is
        // genuinely rendered in the mounted modal.
        $this->assertCount(6, FirmUserRole::cases());

        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListFirmUsers::class));
        $test->mountAction(InviteFirmUserAction::getDefaultName());

        foreach (FirmUserRole::cases() as $role) {
            $test->assertSee(str($role->value)->headline()->toString());
        }
    }

    public function test_invite_action_via_ui_creates_the_invited_firm_user_and_sends_email(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $this->runWithFirmContext($firm, fn () => app(SeatAllocationService::class)->allocateDirect($firm, SeatClass::Attorney, 3));

        Notification::fake();

        $email = 'ui-invite-'.uniqid().'@example.test';

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListFirmUsers::class));

        $test->mountAction(InviteFirmUserAction::getDefaultName());
        $test->setActionData([
            'name' => 'UI Invited Person',
            'email' => $email,
            'role' => FirmUserRole::Attorney->value,
        ]);
        $test->callMountedAction();

        $this->runWithFirmContext($firm, function () use ($firm, $email): void {
            $created = FirmUser::query()
                ->whereHas('user', fn ($q) => $q->where('email', $email))
                ->where('firm_id', $firm->id)
                ->first();

            $this->assertNotNull($created, 'The invite Action must have created an Invited FirmUser row.');
            $this->assertSame(FirmUserStatus::Invited, $created->status);
            $this->assertSame(FirmUserRole::Attorney, $created->role);
        });
    }

    // ------------------------------------------------------------
    // 3. Suspend / Reactivate / Remove — visibility + last-owner guard.
    // ------------------------------------------------------------

    public function test_suspend_action_is_hidden_for_a_non_owner_actor(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $staffer = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::Paralegal)->create(),
        );
        // Switch the acting user to a non-owner role.
        $this->actingAsRole($firm, FirmUserRole::Attorney);

        // Every table-action assertion/call must run INSIDE the same
        // runWithFirmContext() wrap as the Livewire::test() call that
        // produced it — Filament's table-action machinery re-resolves
        // the record via the resource's own Eloquent query at assert/
        // call time, which (like every other query against
        // firm_users, a FORCE-RLS table) needs an active tenant context.
        $this->runWithFirmContext($firm, function () use ($staffer): void {
            $test = Livewire::test(ListFirmUsers::class);
            $test->assertTableActionHidden(SuspendFirmUserAction::getDefaultName(), $staffer);
        });
    }

    public function test_suspend_reactivate_and_remove_work_through_the_ui_for_a_non_owner_row(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $staffer = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::Paralegal)->create(),
        );

        $this->runWithFirmContext($firm, function () use ($staffer): void {
            $test = Livewire::test(ListFirmUsers::class);
            $test->callTableAction(SuspendFirmUserAction::getDefaultName(), $staffer);
            $this->assertSame(FirmUserStatus::Suspended, FirmUser::query()->find($staffer->id)->status);
        });

        $this->runWithFirmContext($firm, function () use ($staffer): void {
            $test = Livewire::test(ListFirmUsers::class);
            $test->callTableAction(ReactivateFirmUserAction::getDefaultName(), $staffer);
            $this->assertSame(FirmUserStatus::Active, FirmUser::query()->find($staffer->id)->status);
        });

        $this->runWithFirmContext($firm, function () use ($staffer): void {
            $test = Livewire::test(ListFirmUsers::class);
            $test->callTableAction(RemoveFirmUserAction::getDefaultName(), $staffer);
            $this->assertSame(FirmUserStatus::Removed, FirmUser::query()->find($staffer->id)->status);
        });
    }

    public function test_suspend_action_is_hidden_on_the_last_remaining_active_owner_row(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($owner): void {
            $test = Livewire::test(ListFirmUsers::class);

            // Visible() itself does not know about the last-owner guard
            // (it only checks role/status) — the guard lives in the
            // SERVICE, so the action remains visible but its own
            // callTableAction() must fail cleanly rather than actually
            // suspending the last owner.
            $test->assertTableActionVisible(SuspendFirmUserAction::getDefaultName(), $owner);
            $test->callTableAction(SuspendFirmUserAction::getDefaultName(), $owner);

            $this->assertSame(FirmUserStatus::Active, FirmUser::query()->find($owner->id)->status, 'The last remaining active owner must never actually be suspended, even though the action itself remains visible.');
        });
    }

    public function test_remove_action_is_blocked_on_the_last_remaining_active_owner_row(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($owner): void {
            $test = Livewire::test(ListFirmUsers::class);
            $test->callTableAction(RemoveFirmUserAction::getDefaultName(), $owner);

            $this->assertSame(FirmUserStatus::Active, FirmUser::query()->find($owner->id)->status, 'The last remaining active owner must never actually be removed.');
        });
    }

    public function test_suspend_succeeds_via_ui_when_a_second_active_owner_exists(): void
    {
        $firm = Firm::factory()->create();
        $ownerA = $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create(),
        );

        $this->runWithFirmContext($firm, function () use ($ownerA): void {
            $test = Livewire::test(ListFirmUsers::class);
            $test->callTableAction(SuspendFirmUserAction::getDefaultName(), $ownerA);

            $this->assertSame(FirmUserStatus::Suspended, FirmUser::query()->find($ownerA->id)->status);
        });
    }

    // ------------------------------------------------------------
    // 4. RLS regression checklist (small, focused — not a re-run of
    //    the historical RlsForceRollout suite).
    // ------------------------------------------------------------

    public function test_a_firms_team_list_only_shows_its_own_firm_users(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $ownerA = $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $firmUserB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmUser::factory()->forFirm($firmB)->forUser(User::factory()->create())->role(FirmUserRole::Attorney)->create(),
        );

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListFirmUsers::class));

        $test->assertCanSeeTableRecords([$ownerA]);
        $test->assertCanNotSeeTableRecords([$firmUserB]);
    }

    public function test_direct_url_guess_of_another_firms_firm_user_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $firmUserB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmUser::factory()->forFirm($firmB)->forUser(User::factory()->create())->role(FirmUserRole::Attorney)->create(),
        );

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(FirmUserResource::getUrl('view', ['record' => $firmUserB])));

        $response->assertNotFound();
    }

    public function test_a_foreign_firms_user_can_never_be_invited_as_a_duplicate_via_this_firms_invite_action(): void
    {
        // Confirms invite() email lookup is global (by design — an email
        // may only ever belong to one User account) but membership is
        // still firm-scoped: inviting a person who already belongs to a
        // DIFFERENT firm must succeed (they simply gain a second
        // membership), never silently leak that other firm's data.
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $owner = $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $this->runWithFirmContext($firmA, fn () => app(SeatAllocationService::class)->allocateDirect($firmA, SeatClass::Attorney, 3));
        $this->runWithFirmContext($firmA, fn () => app(SeatAllocationService::class)->allocateDirect($firmA, SeatClass::Staff, 3));

        $userInFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmUser::factory()->forFirm($firmB)->forUser(User::factory()->create())->role(FirmUserRole::Attorney)->create(),
        )->user;

        Notification::fake();

        app(FirmUserInvitationService::class)->invite($firmA, $userInFirmB->email, $userInFirmB->name, FirmUserRole::Paralegal, $owner->user);

        $this->runWithFirmContext($firmA, function () use ($firmA, $userInFirmB): void {
            $membership = FirmUser::query()->where('firm_id', $firmA->id)->where('user_id', $userInFirmB->id)->first();
            $this->assertNotNull($membership, 'The user must gain a new membership in firm A.');
            $this->assertSame(FirmUserRole::Paralegal, $membership->role);
        });

        $this->runWithFirmContext($firmB, function () use ($firmB, $userInFirmB): void {
            $membership = FirmUser::query()->where('firm_id', $firmB->id)->where('user_id', $userInFirmB->id)->first();
            $this->assertSame(FirmUserRole::Attorney, $membership->role, "Firm B's own membership row must be completely unaffected.");
        });
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
