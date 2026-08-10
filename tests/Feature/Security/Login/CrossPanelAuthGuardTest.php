<?php

namespace Tests\Feature\Security\Login;

use App\Enums\FirmUserStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Section40LimitedPilotSafetyGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CrossPanelAuthGuardTest — internal login/panel access wiring. Proves
 * the two guards (`web` for the firm panel, `platform_admin` for the
 * admin panel) are fully isolated from each other: a session
 * authenticated on one guard has no standing access to the other
 * panel, since Filament resolves each panel's `Filament::auth()`
 * independently per its own authGuard(). Also re-confirms the Section
 * 40 gate's "no public legal document URLs" check still holds now that
 * two panels' real routes are registered.
 */
class CrossPanelAuthGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_guard_authenticated_firm_user_cannot_access_admin_panel(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        $response = $this->actingAs($user, 'web')->get($this->adminUrl());

        $response->assertRedirect($this->adminUrl('/login'));
    }

    public function test_platform_admin_guard_authenticated_admin_cannot_access_firm_panel_through_normal_routes(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $response = $this->actingAs($admin, 'platform_admin')->get($this->firmAppUrl());

        $response->assertRedirect($this->firmAppUrl('/login'));
    }

    public function test_no_public_legal_document_urls_are_exposed_by_either_panel(): void
    {
        $this->assertTrue((new Section40LimitedPilotSafetyGateService)->hasNoPublicLegalDocumentUrls());
    }
}
