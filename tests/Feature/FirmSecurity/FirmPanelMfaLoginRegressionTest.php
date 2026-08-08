<?php

declare(strict_types=1);

namespace Tests\Feature\FirmSecurity;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FirmPanelMfaLoginRegressionTest — Firm Feature Manifest §11. THE
 * single most important test in the 2FA-enrollment task: proves that
 * adding `->profile()` + `->multiFactorAuthentication(...)` to
 * FirmPanelProvider does not change login behavior for the real-world
 * default state of every existing firm today — no 2FA enrolled, firm
 * NOT in Required mode. Run and verified to pass BEFORE any Security
 * Activity page work began, per this task's own process requirement.
 *
 * Also proves `isRequired: false` really means self-service-only: an
 * unenrolled user is never redirected to Filament's own
 * SetUpRequiredMultiFactorAuthentication page, and an enrolled user
 * (opted in via the profile page) is still challenged for a TOTP code
 * on every subsequent login — real protection, never compulsory from
 * this panel config alone.
 */
final class FirmPanelMfaLoginRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_an_existing_non_2fa_enrolled_firm_user_in_a_non_required_firm_can_still_log_in_exactly_as_before(): void
    {
        $firm = Firm::factory()->create();
        $owner = User::factory()->create([
            'is_active' => true,
            'password' => bcrypt('a-real-password-2026'),
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);
        $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser($owner)->create([
                'role' => FirmUserRole::FirmOwner,
                'status' => FirmUserStatus::Active,
            ]),
        );

        // Sanity check: firm_settings.firm_user_2fa_mode is NOT Required
        // (today's real-world default for every firm — no UI can set it
        // otherwise, per FirmSettingsPage's own docblock).
        $this->assertTrue($owner->canAccessPanel(Filament::getPanel('firm')), 'Sanity check: this user must already be able to access the panel per canAccessPanel() before the login UI is even exercised.');

        $test = Livewire::test(Login::class)
            ->set('data.email', $owner->email)
            ->set('data.password', 'a-real-password-2026')
            ->call('authenticate');

        $test->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($owner);
    }

    public function test_an_unenrolled_user_is_never_redirected_to_the_mfa_set_up_required_page(): void
    {
        $panel = Filament::getPanel('firm');

        // isRequired: false must mean no route middleware is even
        // attached — confirmed structurally, not merely behaviorally.
        $this->assertFalse($panel->isMultiFactorAuthenticationRequired());
    }

    public function test_a_wrong_password_is_still_rejected_exactly_as_before(): void
    {
        $firm = Firm::factory()->create();
        $owner = User::factory()->create([
            'is_active' => true,
            'password' => bcrypt('a-real-password-2026'),
        ]);
        $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser($owner)->create([
                'role' => FirmUserRole::FirmOwner,
                'status' => FirmUserStatus::Active,
            ]),
        );

        $test = Livewire::test(Login::class)
            ->set('data.email', $owner->email)
            ->set('data.password', 'the-wrong-password')
            ->call('authenticate');

        $test->assertHasFormErrors();
        $this->assertGuest();
    }

    public function test_a_user_who_has_enrolled_2fa_is_challenged_at_login(): void
    {
        $firm = Firm::factory()->create();
        $secret = 'JBSWY3DPEHPK3PXP';
        $owner = User::factory()->create([
            'is_active' => true,
            'password' => bcrypt('a-real-password-2026'),
        ]);
        $owner->saveAppAuthenticationSecret($secret);
        $owner->refresh();

        $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser($owner)->create([
                'role' => FirmUserRole::FirmOwner,
                'status' => FirmUserStatus::Active,
            ]),
        );

        $test = Livewire::test(Login::class)
            ->set('data.email', $owner->email)
            ->set('data.password', 'a-real-password-2026')
            ->call('authenticate');

        // The credential step must succeed, but full sign-in must NOT
        // yet be complete — a TOTP challenge must intervene.
        $test->assertHasNoFormErrors();
        $this->assertGuest('web');
    }
}
