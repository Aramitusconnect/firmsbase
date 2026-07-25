<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\FirmUserRole;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformSecurityDashboardPage;
use App\Filament\Pages\PlatformTenantIsolationPage;
use App\Filament\Resources\FirmResource;
use App\Filament\Resources\FirmUserResource;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformAdminControlCenterAccessTest — Phase 1 FirmsVault Admin
 * Control Center. Full HTTP-level access-gating proof for the new
 * FirmResource / FirmUserResource / PlatformSecurityDashboardPage /
 * PlatformTenantIsolationPage routes, mirroring
 * IntegrationOverviewAdminAuthenticationTest's established shape
 * exactly: guest denied, an authenticated firm-panel `User` (wrong
 * guard entirely) denied, a genuine platform_admin with no role grant
 * forbidden, and a genuinely eligible platform_admin reaching the page
 * (positive control, so the denial proofs are not merely "everyone
 * gets a 500").
 */
final class PlatformAdminControlCenterAccessTest extends TestCase
{
    use RefreshDatabase;

    private function firmPanelUser(): User
    {
        $firm = Firm::factory()->activated()->create();
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create()
        );

        return $firmUser->user;
    }

    /**
     * FirmsVault Admin Control Center MFA design proposal — cascading
     * update. MFA is now mandatory panel-wide
     * (EnsurePlatformAdminMfaIsEnrolledAndVerified, added to
     * AdminPanelProvider::authMiddleware() alongside this checkpoint),
     * so every fixture in this file that is meant to reach past login
     * (i.e. every case except the deliberately-unauthorized ones, which
     * a redirect-to-MFA-setup would also incidentally produce and so
     * must stay easy to tell apart from a real 403) needs a confirmed
     * TOTP enrollment, or the new middleware redirects them to the
     * set-up-required page before this test's own Policy-layer
     * assertion is ever reached — a 302 where a 403/200 was expected.
     * This mirrors this codebase's own established "cascading forward"
     * pattern for updating pre-existing structural/allowlist tests
     * after a checkpoint changes what an existing fixture needs to
     * satisfy (see Checkpoint 11's FirmIntegrationSuperAdminBoundaryStructuralTest
     * precedent).
     */
    private function platformAdmin(array $attributes = []): PlatformAdmin
    {
        return PlatformAdmin::factory()->create(array_merge([
            'is_active' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ], $attributes));
    }

    // ------------------------------------------------------------
    // FirmResource
    // ------------------------------------------------------------

    public function test_guest_is_redirected_from_the_firms_list(): void
    {
        $this->get(FirmResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_firm_panel_user_is_denied_the_firms_list(): void
    {
        $this->actingAs($this->firmPanelUser())->get(FirmResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden_from_the_firms_list(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'platform_admin')->get(FirmResource::getUrl())->assertForbidden();
    }

    public function test_a_platform_admin_with_a_sales_role_is_forbidden_from_the_firms_list(): void
    {
        $admin = $this->platformAdmin();
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SalesRep);

        $this->actingAs($admin, 'platform_admin')->get(FirmResource::getUrl())->assertForbidden();
    }

    public function test_an_eligible_platform_admin_can_reach_the_firms_list(): void
    {
        $admin = $this->platformAdmin();
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        Firm::factory()->count(2)->create();

        $response = $this->actingAs($admin, 'platform_admin')->get(FirmResource::getUrl());

        $response->assertOk();
    }

    public function test_an_eligible_platform_admin_can_view_a_single_firm(): void
    {
        $admin = $this->platformAdmin();
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        $firm = Firm::factory()->create();

        $response = $this->actingAs($admin, 'platform_admin')->get(FirmResource::getUrl('view', ['record' => $firm]));

        $response->assertOk();
    }

    // ------------------------------------------------------------
    // FirmUserResource
    // ------------------------------------------------------------

    public function test_guest_is_redirected_from_the_firm_users_list(): void
    {
        $this->get(FirmUserResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden_from_the_firm_users_list(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'platform_admin')->get(FirmUserResource::getUrl())->assertForbidden();
    }

    public function test_an_eligible_platform_admin_can_reach_the_firm_users_list_and_see_cross_firm_rows(): void
    {
        $admin = $this->platformAdmin();
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        $firmA = Firm::factory()->create(['name' => 'Cross Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Cross Firm B']);
        $this->createWithFirmContext($firmA, fn () => FirmUser::factory()->forFirm($firmA)->create());
        $this->createWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->create());

        $response = $this->actingAs($admin, 'platform_admin')->get(FirmUserResource::getUrl());

        $response->assertOk();
        $response->assertSee('Cross Firm A');
        $response->assertSee('Cross Firm B');
    }

    public function test_an_eligible_platform_admin_can_view_a_single_firm_user_via_the_composite_route(): void
    {
        $admin = $this->platformAdmin();
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        $firm = Firm::factory()->create();
        $firmUser = $this->createWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create());

        $response = $this->actingAs($admin, 'platform_admin')->get(FirmUserResource::getUrl('view', [
            'firmUuid' => $firm->uuid,
            'firmUserUuid' => $firmUser->uuid,
        ]));

        $response->assertOk();
    }

    public function test_a_firm_users_composite_view_route_404s_when_the_firm_user_belongs_to_a_different_firm(): void
    {
        $admin = $this->platformAdmin();
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $firmUserA = $this->createWithFirmContext($firmA, fn () => FirmUser::factory()->forFirm($firmA)->create());

        $response = $this->actingAs($admin, 'platform_admin')->get(FirmUserResource::getUrl('view', [
            'firmUuid' => $firmB->uuid,
            'firmUserUuid' => $firmUserA->uuid,
        ]));

        $response->assertNotFound();
    }

    // ------------------------------------------------------------
    // PlatformSecurityDashboardPage
    // ------------------------------------------------------------

    public function test_guest_is_redirected_from_the_security_dashboard(): void
    {
        $this->get(PlatformSecurityDashboardPage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden_from_the_security_dashboard(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'platform_admin')->get(PlatformSecurityDashboardPage::getUrl())->assertForbidden();
    }

    public function test_a_security_auditor_can_reach_the_security_dashboard(): void
    {
        $admin = $this->platformAdmin();
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SecurityAuditor);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformSecurityDashboardPage::getUrl());

        $response->assertOk();
    }

    public function test_a_billing_admin_is_forbidden_from_the_security_dashboard(): void
    {
        $admin = $this->platformAdmin();
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::BillingAdmin);

        $this->actingAs($admin, 'platform_admin')->get(PlatformSecurityDashboardPage::getUrl())->assertForbidden();
    }

    // ------------------------------------------------------------
    // PlatformTenantIsolationPage
    // ------------------------------------------------------------

    public function test_guest_is_redirected_from_the_tenant_isolation_page(): void
    {
        $this->get(PlatformTenantIsolationPage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden_from_the_tenant_isolation_page(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'platform_admin')->get(PlatformTenantIsolationPage::getUrl())->assertForbidden();
    }

    public function test_a_security_auditor_can_reach_the_tenant_isolation_page(): void
    {
        $admin = $this->platformAdmin();
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SecurityAuditor);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformTenantIsolationPage::getUrl());

        $response->assertOk();
        $response->assertSee('Runtime Database Role');
    }
}
