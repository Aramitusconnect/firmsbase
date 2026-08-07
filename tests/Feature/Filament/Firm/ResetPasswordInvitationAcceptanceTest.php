<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Firm;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\FirmOrganizationProvisioningMode;
use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Filament\Firm\Pages\Auth\ResetPassword;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Notifications\FirmOwnerInvitationNotification;
use App\Services\FirmProvisioningService;
use App\ValueObjects\FirmProvisioningInput;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proves the fix for a real, live-staging-confirmed deadlock: Filament's
 * own stock ResetPassword page refuses to complete a reset unless
 * $user->canAccessPanel($panel) already returns true — but that can
 * never be true yet for a brand-new invited firm owner, since
 * canAccessPanel() requires an ACTIVE FirmUser membership
 * (User::activeFirmUser()), and the only thing that flips Invited to
 * Active is AppServiceProvider::registerFirmOwnerInvitationAcceptance(),
 * a listener on the very PasswordReset event the stock page refuses to
 * fire in this exact case. Every invited owner's first setup attempt
 * failed with a generic "invalid user" notification, no matter how many
 * times the invitation was resent — confirmed directly against the live
 * staging database before this fix (see docs/ecs/state-adoption-plan.md
 * for the infrastructure-mission context this was found under).
 *
 * App\Filament\Firm\Pages\Auth\ResetPassword narrowly widens that one
 * precondition; these tests prove the widening is exactly as narrow as
 * intended — it unblocks ONLY a genuinely pending first-time invitation,
 * and every other canAccessPanel() failure reason (deactivated account,
 * 2FA non-compliance, login-policy block, unknown email) is refused
 * exactly as the stock Filament page already refused it.
 */
final class ResetPasswordInvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): PlatformAdmin
    {
        return PlatformAdmin::factory()->create(['is_active' => true]);
    }

    private function service(): FirmProvisioningService
    {
        return app(FirmProvisioningService::class);
    }

    /**
     * @return array{0: Firm, 1: User, 2: string} firm, owner, raw token
     */
    private function provisionInvitedOwnerWithToken(): array
    {
        $ownerEmail = 'owner-'.Str::random(8).'@example.test';

        Notification::fake();

        $result = $this->service()->provision(new FirmProvisioningInput(
            idempotencyKey: (string) Str::uuid(),
            firmName: 'Reset Test Firm',
            legalName: null,
            organizationMode: FirmOrganizationProvisioningMode::None,
            organizationId: null,
            newOrganizationName: null,
            ownerName: 'Owner Name',
            ownerEmail: $ownerEmail,
            reuseExistingUser: false,
            customerType: CustomerType::LawFirm,
            deploymentMode: DeploymentMode::Saas,
            planId: null,
            trialDaysOverride: null,
            note: null,
        ), $this->actor());

        // provision()'s own dispatchOwnerInvitation() already minted a
        // real token via Password::broker('users')->sendResetLink() and
        // sent it inside a FirmOwnerInvitationNotification — the raw
        // token is never returned to the caller (by design, see that
        // method's own docblock), so it's read back here from the
        // faked notification's own (public, inherited from Laravel's
        // stock ResetPassword notification) $token property instead of
        // calling sendResetLink() a second time — a second real call
        // this soon would itself hit the same 60-second throttle this
        // whole investigation was about.
        $capturedToken = null;

        Notification::assertSentTo(
            $result->owner,
            FirmOwnerInvitationNotification::class,
            function (FirmOwnerInvitationNotification $notification) use (&$capturedToken): bool {
                $capturedToken = $notification->token;

                return true;
            }
        );

        $this->assertNotNull($capturedToken, 'Expected provision() to have sent an invitation carrying a token.');

        return [$result->firm, $result->owner, $capturedToken];
    }

    public function test_a_brand_new_invited_owner_can_complete_first_time_password_setup(): void
    {
        [$firm, $owner, $token] = $this->provisionInvitedOwnerWithToken();

        $this->assertFalse($owner->canAccessPanel(Filament::getPanel('firm')), 'Sanity check: the owner must NOT already have panel access before completing setup.');

        Livewire::test(ResetPassword::class, ['email' => $owner->email, 'token' => $token])
            ->set('password', 'a-real-new-password-2026')
            ->set('passwordConfirmation', 'a-real-new-password-2026')
            ->call('resetPassword');

        $owner->refresh();
        $this->assertTrue(Hash::check('a-real-new-password-2026', $owner->password), 'The owner\'s password must actually be set.');

        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::query()->where('firm_id', $firm->id)->where('user_id', $owner->id)->first(),
        );

        $this->assertSame(FirmUserStatus::Active, $firmUser->status, 'The invitation-acceptance listener must have activated the FirmUser membership.');
        $this->assertNotNull($firmUser->invitation_accepted_at);

        $this->assertTrue($owner->canAccessPanel(Filament::getPanel('firm')), 'The owner must now be able to access the firm panel.');
    }

    public function test_an_inactive_account_is_still_refused_even_with_a_pending_invitation(): void
    {
        [$firm, $owner, $token] = $this->provisionInvitedOwnerWithToken();

        $owner->forceFill(['is_active' => false])->save();

        Livewire::test(ResetPassword::class, ['email' => $owner->email, 'token' => $token])
            ->set('password', 'a-real-new-password-2026')
            ->set('passwordConfirmation', 'a-real-new-password-2026')
            ->call('resetPassword');

        $owner->refresh();
        $this->assertFalse(Hash::check('a-real-new-password-2026', $owner->password ?? ''), 'A deactivated account must never have its password set via this path.');

        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::query()->where('firm_id', $firm->id)->where('user_id', $owner->id)->first(),
        );
        $this->assertSame(FirmUserStatus::Invited, $firmUser->status, 'A deactivated account\'s pending membership must not be activated.');
    }

    public function test_an_already_active_member_blocked_by_login_policy_is_still_refused(): void
    {
        $firm = Firm::factory()->create();
        $owner = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($owner)->create([
            'role' => FirmUserRole::FirmOwner,
            'status' => FirmUserStatus::Suspended,
        ]);

        $capturedToken = null;
        Password::broker('users')->sendResetLink(
            ['email' => $owner->email],
            function ($user, string $token) use (&$capturedToken): string {
                $capturedToken = $token;

                return Password::RESET_LINK_SENT;
            }
        );
        $this->assertNotNull($capturedToken);

        Livewire::test(ResetPassword::class, ['email' => $owner->email, 'token' => $capturedToken])
            ->set('password', 'a-real-new-password-2026')
            ->set('passwordConfirmation', 'a-real-new-password-2026')
            ->call('resetPassword');

        $owner->refresh();
        $this->assertFalse(Hash::check('a-real-new-password-2026', $owner->password ?? ''), 'A suspended member (no pending Invited row — already a real member) must still be refused exactly as the stock Filament page would refuse it.');
    }

    public function test_a_genuinely_unknown_email_is_still_refused(): void
    {
        Livewire::test(ResetPassword::class, ['email' => 'nobody-'.Str::random(8).'@example.test', 'token' => 'not-a-real-token'])
            ->set('password', 'a-real-new-password-2026')
            ->set('passwordConfirmation', 'a-real-new-password-2026')
            ->call('resetPassword');

        $this->assertSame(0, User::query()->where('email', 'like', 'nobody-%@example.test')->count(), 'No user should exist or be created for an unknown email.');
    }

    public function test_an_ordinary_forgot_password_reset_for_an_already_active_member_still_works(): void
    {
        $firm = Firm::factory()->create();
        $owner = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($owner)->create([
            'role' => FirmUserRole::FirmOwner,
            'status' => FirmUserStatus::Active,
        ]);

        $this->assertTrue($owner->canAccessPanel(Filament::getPanel('firm')), 'Sanity check: an ordinary active member must already have panel access.');

        $capturedToken = null;
        Password::broker('users')->sendResetLink(
            ['email' => $owner->email],
            function ($user, string $token) use (&$capturedToken): string {
                $capturedToken = $token;

                return Password::RESET_LINK_SENT;
            }
        );

        Livewire::test(ResetPassword::class, ['email' => $owner->email, 'token' => $capturedToken])
            ->set('password', 'a-different-new-password-2026')
            ->set('passwordConfirmation', 'a-different-new-password-2026')
            ->call('resetPassword');

        $owner->refresh();
        $this->assertTrue(Hash::check('a-different-new-password-2026', $owner->password), 'An ordinary forgot-password reset for an already-active member must be unaffected by this fix.');
    }
}
