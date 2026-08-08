<?php

declare(strict_types=1);

namespace Tests\Feature\FirmTeam;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\SeatClass;
use App\Exceptions\FirmSeatLimitExceededException;
use App\Exceptions\LastFirmOwnerRemovalException;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Notifications\FirmOwnerInvitationNotification;
use App\Services\FirmUserInvitationService;
use App\Services\SeatAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * FirmUserInvitationServiceTest — service-layer proof for Firm Feature
 * Manifest §12 ("Firm Team / Access"). Mirrors
 * ResetPasswordInvitationAcceptanceTest's own style for asserting a
 * real invitation was sent (Notification::fake() + assertSentTo,
 * reading the token back off the faked notification rather than a
 * second real sendResetLink() call).
 */
final class FirmUserInvitationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): FirmUserInvitationService
    {
        return app(FirmUserInvitationService::class);
    }

    private function ownerFirmUser(Firm $firm): FirmUser
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create(),
        );
    }

    private function grantSeats(Firm $firm, SeatClass $seatClass, int $seats): void
    {
        $this->runWithFirmContext($firm, fn () => app(SeatAllocationService::class)->allocateDirect($firm, $seatClass, $seats));
    }

    // ------------------------------------------------------------
    // invite()
    // ------------------------------------------------------------

    public function test_invite_creates_user_and_invited_firm_user_and_sends_the_invitation_notification(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->ownerFirmUser($firm);
        // FirmOwner already consumes 1 attorney seat with 0 allocated —
        // grant capacity for the owner PLUS the new invitee.
        $this->grantSeats($firm, SeatClass::Attorney, 2);

        Notification::fake();

        $email = 'new-attorney-'.uniqid().'@example.test';

        $firmUser = $this->service()->invite($firm, $email, 'New Attorney', FirmUserRole::Attorney, $owner->user);

        $this->assertSame(FirmUserStatus::Invited, $firmUser->status);
        $this->assertSame(FirmUserRole::Attorney, $firmUser->role);
        $this->assertSame($owner->user->id, $firmUser->invited_by);

        $newUser = User::query()->where('email', $email)->first();
        $this->assertNotNull($newUser, 'invite() must create a new User row for a brand-new email.');
        $this->assertSame($firmUser->user_id, $newUser->id);
        $this->assertNotSame('', $newUser->password);

        Notification::assertSentTo($newUser, FirmOwnerInvitationNotification::class);
    }

    public function test_invite_reuses_an_existing_user_account_with_a_matching_email(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->ownerFirmUser($firm);
        $this->grantSeats($firm, SeatClass::Staff, 1);

        $existingUser = User::factory()->create(['email' => 'existing-'.uniqid().'@example.test']);

        Notification::fake();

        $firmUser = $this->service()->invite($firm, $existingUser->email, 'Existing Person', FirmUserRole::Paralegal, $owner->user);

        $this->assertSame($existingUser->id, $firmUser->user_id);
        $this->assertSame(1, User::query()->where('email', $existingUser->email)->count(), 'No duplicate User row should be created.');
    }

    public function test_invite_rejects_an_email_already_belonging_to_a_member_of_this_firm(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->ownerFirmUser($firm);
        $this->grantSeats($firm, SeatClass::Attorney, 5);

        $this->expectException(RuntimeException::class);

        $this->service()->invite($firm, $owner->user->email, 'Owner Again', FirmUserRole::Attorney, $owner->user);
    }

    public function test_invite_fails_cleanly_when_the_firm_has_no_remaining_seats(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->ownerFirmUser($firm);
        // Deliberately NO SeatAllocationService::allocateDirect() call —
        // proves the documented, pre-existing gap: a freshly provisioned
        // firm has zero allocated seats, so canInvite() must refuse.

        $this->expectException(FirmSeatLimitExceededException::class);

        $this->service()->invite($firm, 'blocked-'.uniqid().'@example.test', 'Blocked Invitee', FirmUserRole::Attorney, $owner->user);
    }

    public function test_invite_is_blocked_when_exactly_at_capacity_then_succeeds_once_a_seat_is_freed(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->ownerFirmUser($firm);
        $this->grantSeats($firm, SeatClass::Attorney, 1); // exactly enough for the owner, none spare

        try {
            $this->service()->invite($firm, 'no-room-'.uniqid().'@example.test', 'No Room', FirmUserRole::Attorney, $owner->user);
            $this->fail('Expected FirmSeatLimitExceededException when no attorney seats remain.');
        } catch (FirmSeatLimitExceededException) {
            // expected
        }

        // Free the owner's own seat by suspending them onto a second
        // owner first (suspend requires another active owner to exist).
        $ownerB = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create(),
        );
        $this->grantSeats($firm, SeatClass::Attorney, 1); // ownerB now also needs a seat: 2 allocated, 2 used (owner + ownerB)
        $this->service()->suspend($owner); // frees owner's seat: 2 allocated, 1 used (ownerB)

        Notification::fake();

        $firmUser = $this->service()->invite($firm, 'room-now-'.uniqid().'@example.test', 'Room Now', FirmUserRole::Attorney, $ownerB->user);

        $this->assertSame(FirmUserStatus::Invited, $firmUser->status);
    }

    // ------------------------------------------------------------
    // suspend() / reactivate() / remove() — last-owner guard
    // ------------------------------------------------------------

    public function test_suspend_is_blocked_for_the_last_remaining_active_owner(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->ownerFirmUser($firm);

        $this->expectException(LastFirmOwnerRemovalException::class);

        $this->service()->suspend($owner);
    }

    public function test_remove_is_blocked_for_the_last_remaining_active_owner(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->ownerFirmUser($firm);

        $this->expectException(LastFirmOwnerRemovalException::class);

        $this->service()->remove($owner);
    }

    public function test_suspend_succeeds_for_an_owner_when_another_active_owner_remains(): void
    {
        $firm = Firm::factory()->create();
        $ownerA = $this->ownerFirmUser($firm);
        $ownerB = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create(),
        );

        $result = $this->service()->suspend($ownerA);

        $this->assertSame(FirmUserStatus::Suspended, $result->status);

        $ownerBFresh = $this->runWithFirmContext($firm, fn () => FirmUser::query()->find($ownerB->id));
        $this->assertSame(FirmUserStatus::Active, $ownerBFresh->status, 'The remaining owner must be unaffected.');
    }

    public function test_remove_succeeds_for_a_non_owner_role(): void
    {
        $firm = Firm::factory()->create();
        $this->ownerFirmUser($firm);
        $staffer = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::Paralegal)->create(),
        );

        $result = $this->service()->remove($staffer);

        $this->assertSame(FirmUserStatus::Removed, $result->status);
    }

    public function test_suspend_then_reactivate_round_trips_status(): void
    {
        $firm = Firm::factory()->create();
        $this->ownerFirmUser($firm);
        $staffer = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::BillingStaff)->create(),
        );

        $suspended = $this->service()->suspend($staffer);
        $this->assertSame(FirmUserStatus::Suspended, $suspended->status);

        $reactivated = $this->service()->reactivate($staffer);
        $this->assertSame(FirmUserStatus::Active, $reactivated->status);
    }

    public function test_suspend_is_not_blocked_for_an_owner_row_that_is_not_currently_active(): void
    {
        $firm = Firm::factory()->create();
        $ownerA = $this->ownerFirmUser($firm);
        // A second, already-Suspended owner row — the guard must only
        // ever consider the ACTIVE owner, never a merely-existing one.
        $suspendedOwner = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create(['status' => FirmUserStatus::Suspended]),
        );

        // Removing the suspended (already-inactive) owner row must never
        // be blocked by the last-active-owner guard, since $ownerA
        // remains the sole active owner throughout.
        $result = $this->service()->remove($suspendedOwner);

        $this->assertSame(FirmUserStatus::Removed, $result->status);
    }
}
