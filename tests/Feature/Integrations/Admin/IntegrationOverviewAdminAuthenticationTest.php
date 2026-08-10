<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\FirmUserRole;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Filament\Pages\PlatformFirmIntegrationsPage;
use App\Filament\Pages\PlatformIntegrationOverviewPage;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IntegrationOverviewAdminAuthenticationTest — Checkpoint 11 (frozen-
 * design-post-security-review.md §1, §2, §11, §12). Identity-level
 * proof for all three new admin-panel routes
 * (PlatformIntegrationOverviewPage / PlatformFirmIntegrationsPage /
 * PlatformFirmIntegrationDetailPage): an unauthenticated request and an
 * authenticated Firm-panel `User` (a completely different guard —
 * `web`, never `platform_admin`) must both be denied on every route,
 * regardless of any `PlatformRoleCode`. Role-CEILING enforcement
 * (SuperAdmin/PlatformAdmin/ImplementationSpecialist unconditional,
 * SupportAgent session-gated, everyone else denied) is proven
 * separately in IntegrationAdminRouteAuthorizationTest.php — this file
 * only proves "not a platform_admin at all" is denied everywhere.
 */
final class IntegrationOverviewAdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // Guest (no authentication at all)
    // ------------------------------------------------------------

    public function test_guest_is_redirected_to_platform_admin_login_from_the_overview_page(): void
    {
        $response = $this->get(PlatformIntegrationOverviewPage::getUrl());

        $response->assertRedirect($this->adminUrl('/login'));
    }

    public function test_guest_is_redirected_to_platform_admin_login_from_the_firm_integrations_page(): void
    {
        $firm = Firm::factory()->activated()->create();

        $response = $this->get(PlatformFirmIntegrationsPage::getUrl(['firmUuid' => $firm->uuid]));

        $response->assertRedirect($this->adminUrl('/login'));
    }

    public function test_guest_is_redirected_to_platform_admin_login_from_the_connection_detail_page(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $response = $this->get(PlatformFirmIntegrationDetailPage::getUrl([
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]));

        $response->assertRedirect($this->adminUrl('/login'));
    }

    // ------------------------------------------------------------
    // Firm-panel User (a real, authenticated identity — but on the
    // `web` guard, never `platform_admin`)
    // ------------------------------------------------------------

    public function test_an_authenticated_firm_panel_user_is_still_denied_the_overview_page(): void
    {
        $firm = Firm::factory()->activated()->create();
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create()
        );

        $response = $this->actingAs($firmUser->user)->get(PlatformIntegrationOverviewPage::getUrl());

        // Not authenticated on the platform_admin guard at all — Filament
        // redirects to that panel's own login rather than ever reaching
        // canAccess().
        $response->assertRedirect($this->adminUrl('/login'));
    }

    public function test_an_authenticated_firm_panel_user_is_still_denied_the_firm_integrations_page(): void
    {
        $firm = Firm::factory()->activated()->create();
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create()
        );

        $response = $this->actingAs($firmUser->user)->get(PlatformFirmIntegrationsPage::getUrl(['firmUuid' => $firm->uuid]));

        $response->assertRedirect($this->adminUrl('/login'));
    }

    public function test_an_authenticated_firm_panel_user_is_still_denied_the_connection_detail_page(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create()
        );

        $response = $this->actingAs($firmUser->user)->get(PlatformFirmIntegrationDetailPage::getUrl([
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]));

        $response->assertRedirect($this->adminUrl('/login'));
    }

    // ------------------------------------------------------------
    // A genuine platform_admin identity, but with NO role grant at all
    // ------------------------------------------------------------

    public function test_a_platform_admin_with_no_role_grant_is_forbidden_on_the_overview_page(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformIntegrationOverviewPage::getUrl());

        $response->assertForbidden();
    }

    public function test_a_platform_admin_with_no_role_grant_is_forbidden_on_the_firm_integrations_page(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformFirmIntegrationsPage::getUrl(['firmUuid' => $firm->uuid]));

        $response->assertForbidden();
    }

    public function test_a_platform_admin_with_no_role_grant_is_forbidden_on_the_connection_detail_page(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformFirmIntegrationDetailPage::getUrl([
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]));

        $response->assertForbidden();
    }

    // ------------------------------------------------------------
    // Sanity: a genuinely eligible platform_admin CAN reach the overview
    // page (positive control so the three denial proofs above are not
    // merely "everyone gets a 500").
    // ------------------------------------------------------------

    public function test_a_platform_admin_with_super_admin_role_can_reach_the_overview_page(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformIntegrationOverviewPage::getUrl());

        $response->assertOk();
    }
}
