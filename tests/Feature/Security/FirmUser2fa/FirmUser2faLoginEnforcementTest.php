<?php

namespace Tests\Feature\Security\FirmUser2fa;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\TwoFactorMode;
use App\Models\Firm;
use App\Models\FirmSettings;
use App\Models\FirmUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FirmUser2faLoginEnforcementTest — internal login/panel access wiring.
 *
 * Mission 1C (Security Validation, Activation & Staging Proof), section
 * 5: the enforcement point moved from User::canAccessPanel() (a hard
 * panel-wide 403, no path to any page) to
 * EnsureFirmUserMfaComplianceOrRedirectToEnrollment (a redirect to the
 * profile page, where enrollment actually happens) — this file's own
 * assertions are updated to match, not merely relaxed: canAccessPanel()
 * itself now correctly returns true (panel access is granted; only
 * WHICH route you land on differs), and the real HTTP-level proof below
 * confirms both halves — non-profile routes redirect, the profile route
 * itself stays reachable, so a non-compliant user has a real path to
 * become compliant instead of being locked out with none.
 */
class FirmUser2faLoginEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_access_panel_no_longer_denies_non_compliant_users_the_whole_panel(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Required]);
        $user = User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => null]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $panel = Filament::getPanel('firm');

        // Mission 1C: the 2FA-required-but-not-compliant check no longer
        // lives in canAccessPanel() at all — see
        // EnsureFirmUserMfaComplianceOrRedirectToEnrollment for the real
        // enforcement, proven via real HTTP requests below.
        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_can_access_panel_allows_when_2fa_required_and_compliant(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Required]);
        $user = User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => now()]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $panel = Filament::getPanel('firm');

        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_a_non_compliant_required_user_is_redirected_to_the_profile_page_not_locked_out(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Required]);
        $user = User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => null]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $response = $this->actingAs($user, 'web')->get($this->firmAppUrl());

        $response->assertRedirect((string) Filament::getPanel('firm')->getProfileUrl());
    }

    public function test_a_non_compliant_required_user_can_still_reach_the_profile_page_itself(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Required]);
        $user = User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => null]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $profileUrl = (string) Filament::getPanel('firm')->getProfileUrl();

        $response = $this->actingAs($user, 'web')->get($profileUrl);

        // The exact proof of the lockout fix: unlike every other panel
        // route (see the redirect test above), the profile page itself
        // is genuinely reachable — this is where MFA enrollment happens.
        $response->assertOk();
    }

    public function test_a_compliant_required_user_reaches_the_panel_normally(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Required]);
        $user = User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => now()]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $response = $this->actingAs($user, 'web')->get($this->firmAppUrl());

        $response->assertOk();
    }

    public function test_http_login_gate_allows_panel_access_when_2fa_optional_for_a_non_privileged_role(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Optional]);
        $user = User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => null]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->role(FirmUserRole::Paralegal)->create(['status' => FirmUserStatus::Active]);

        $response = $this->actingAs($user, 'web')->get($this->firmAppUrl());

        $response->assertOk();
    }

    /**
     * Non-Payment Completion Program, Workstream 7: even when the firm
     * itself has 2FA set to Optional, an Attorney or FirmOwner is
     * still redirected to enroll — the platform-minimum floor cannot
     * be weakened by a firm's own setting.
     */
    public function test_http_login_gate_redirects_a_privileged_role_to_enroll_even_when_firm_mode_is_optional(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Optional]);
        $user = User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => null]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->role(FirmUserRole::Attorney)->create(['status' => FirmUserStatus::Active]);

        $response = $this->actingAs($user, 'web')->get($this->firmAppUrl());

        $response->assertRedirect((string) Filament::getPanel('firm')->getProfileUrl());
    }
}
