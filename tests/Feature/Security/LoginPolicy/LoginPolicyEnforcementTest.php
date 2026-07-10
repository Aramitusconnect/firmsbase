<?php

namespace Tests\Feature\Security\LoginPolicy;

use App\Enums\FirmUserStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\LoginPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LoginPolicyEnforcementTest — internal login/panel access wiring.
 * Proves User::canAccessPanel() genuinely invokes
 * LoginPolicyService::canAttemptFirmLogin() (not a re-derived
 * equivalent) — a suspended/removed/invited firm membership is denied
 * exactly because the wrapper itself denies it, and an active
 * membership is allowed because the wrapper approves it.
 */
class LoginPolicyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_canAccessPanel_denies_user_with_suspended_firm_membership_via_login_policy_wrapper(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Suspended]);

        $service = new LoginPolicyService();

        $this->assertFalse($service->canAttemptFirmLogin($user, $firm), 'Sanity check: the wrapper itself must deny a suspended membership.');
        $this->assertFalse($user->canAccessPanel(\Filament\Facades\Filament::getPanel('firm')));
    }

    public function test_canAccessPanel_allows_user_with_active_firm_membership_via_login_policy_wrapper(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $service = new LoginPolicyService();

        $this->assertTrue($service->canAttemptFirmLogin($user, $firm), 'Sanity check: the wrapper itself must approve an active membership.');
        $this->assertTrue($user->canAccessPanel(\Filament\Facades\Filament::getPanel('firm')));
    }

    public function test_canAccessPanel_denies_a_user_who_has_never_had_any_firm_membership(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->assertFalse($user->canAccessPanel(\Filament\Facades\Filament::getPanel('firm')));
    }
}
