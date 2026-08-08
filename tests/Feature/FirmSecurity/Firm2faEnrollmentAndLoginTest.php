<?php

declare(strict_types=1);

namespace Tests\Feature\FirmSecurity;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\EditProfile;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

/**
 * Firm2faEnrollmentAndLoginTest — Firm Feature Manifest §11. Proves the
 * self-service enrollment/recovery flow end-to-end: a firm user can
 * enroll (secret + recovery codes saved via the exact same
 * `Filament\Auth\MultiFactor\App\AppAuthentication` mechanism
 * `FirmPanelProvider` now registers), and subsequent logins genuinely
 * require a TOTP code — either the real code or a valid recovery code.
 * Also proves `->profile()` actually surfaced the MFA management UI on
 * the stock `EditProfile` page (Filament's own, reused as-is).
 */
final class Firm2faEnrollmentAndLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_the_profile_page_exposes_multi_factor_authentication_management(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->actingAsOwner($firm);

        $test = Livewire::test(EditProfile::class);

        // Filament's own stock content — proves ->profile() +
        // ->multiFactorAuthentication(...) actually wired the MFA
        // management schema onto this panel's profile page, not merely
        // that the page itself loads.
        $test->assertSuccessful();
        $test->assertSee('Authenticator app');

        unset($owner);
    }

    public function test_a_user_can_enroll_totp_receive_recovery_codes_and_then_log_in_with_a_totp_code(): void
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

        $provider = app(AppAuthentication::class)->recoverable();
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();

        // Enrollment — the exact same provider API the profile page's
        // own SetUpAppAuthenticationAction calls under the hood.
        $provider->saveSecret($owner, $secret);
        $recoveryCodes = $provider->generateRecoveryCodes();
        $provider->saveRecoveryCodes($owner, $recoveryCodes);
        $owner->refresh();

        $this->assertNotNull($owner->two_factor_confirmed_at);
        $this->assertCount(8, $owner->getAppAuthenticationRecoveryCodes());

        // First step: credentials only — must NOT complete sign-in yet.
        $test = Livewire::test(Login::class)
            ->set('data.email', $owner->email)
            ->set('data.password', 'a-real-password-2026')
            ->call('authenticate');

        $test->assertHasNoFormErrors();
        $this->assertGuest('web');

        // Second step: a real, freshly-generated TOTP code completes
        // sign-in.
        $code = $google2fa->getCurrentOtp($secret);

        $test->set('data.multiFactor.app.code', $code)
            ->call('authenticate');

        $test->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($owner);
    }

    public function test_a_wrong_totp_code_does_not_complete_sign_in(): void
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

        $provider = app(AppAuthentication::class);
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();
        $provider->saveSecret($owner, $secret);
        $owner->refresh();

        $test = Livewire::test(Login::class)
            ->set('data.email', $owner->email)
            ->set('data.password', 'a-real-password-2026')
            ->call('authenticate');

        $test->set('data.multiFactor.app.code', '000000')
            ->call('authenticate');

        $test->assertHasFormErrors();
        $this->assertGuest('web');
    }

    public function test_a_valid_recovery_code_completes_sign_in(): void
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

        $provider = app(AppAuthentication::class)->recoverable();
        $secret = (new Google2FA)->generateSecretKey();
        $provider->saveSecret($owner, $secret);
        $recoveryCodes = $provider->generateRecoveryCodes();
        $provider->saveRecoveryCodes($owner, $recoveryCodes);
        $owner->refresh();

        $test = Livewire::test(Login::class)
            ->set('data.email', $owner->email)
            ->set('data.password', 'a-real-password-2026')
            ->call('authenticate');

        $test->set('data.multiFactor.app.recoveryCode', $recoveryCodes[0])
            ->call('authenticate');

        $test->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($owner);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function actingAsOwner(Firm $firm): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
