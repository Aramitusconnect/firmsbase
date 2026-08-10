<?php

namespace Tests\Feature\Security\FirmUser2fa;

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
 * Proves User::canAccessPanel() (the real Filament login/panel gate,
 * checked by Filament on every request via
 * Filament\Http\Middleware\Authenticate) actually enforces
 * FirmUser2faPolicyService: a firm in Required mode denies panel
 * access to a non-compliant firm user, both at the method level and at
 * the real HTTP level, and allows it once compliant.
 */
class FirmUser2faLoginEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_access_panel_denies_when_2fa_required_and_not_compliant(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Required]);
        $user = User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => null]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $panel = Filament::getPanel('firm');

        $this->assertFalse($user->canAccessPanel($panel));
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

    public function test_http_login_gate_denies_panel_access_when_2fa_required_and_not_compliant(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Required]);
        $user = User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => null]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $response = $this->actingAs($user, 'web')->get($this->firmAppUrl());

        $response->assertForbidden();
    }

    public function test_http_login_gate_allows_panel_access_when_2fa_optional(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Optional]);
        $user = User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => null]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $response = $this->actingAs($user, 'web')->get($this->firmAppUrl());

        $response->assertOk();
    }
}
