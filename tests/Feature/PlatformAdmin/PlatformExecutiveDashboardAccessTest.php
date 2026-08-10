<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\Dashboard;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformExecutiveDashboardAccessTest — Phase 1 FirmsVault Admin
 * Control Center, final scope item. Proves the Executive Dashboard
 * (App\Filament\Pages\Dashboard, replacing Filament's stock dashboard —
 * see AdminPanelProvider's own docblock) is reachable by every active,
 * MFA-verified PlatformAdmin regardless of role — deliberately UNLIKE
 * every sibling Platform*Page in this directory, which are each gated
 * to a specific role set — because this is the landing page every
 * authenticated admin sees. It also reuses (never rebuilds) the
 * existing panel-wide gates: guest denied, an inactive admin
 * force-logged-out, and a never-enrolled admin redirected to Filament's
 * own MFA set-up-required page — the same EnsurePlatformAdminMfaIsEnrolledAndVerified
 * behavior EnsurePlatformAdminMfaIsEnrolledAndVerifiedTest already
 * proves against a different protected URL, confirmed here to apply
 * identically to the dashboard route.
 *
 * Per-widget gating is proven separately (a no-role admin sees only the
 * ungated widgets' content; a SuperAdmin sees every section) — this is
 * the "individual cards ... respect whatever access checks already
 * exist" requirement from the mission brief.
 */
final class PlatformExecutiveDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    private function roleService(): PlatformRoleService
    {
        return app(PlatformRoleService::class);
    }

    private function activeMfaVerifiedAdmin(array $attributes = []): PlatformAdmin
    {
        return PlatformAdmin::factory()->create(array_merge([
            'is_active' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ], $attributes));
    }

    public function test_guest_is_redirected_from_the_dashboard(): void
    {
        $this->get(Dashboard::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    /**
     * PlatformAdmin::canAccessPanel() (checked by Filament on every
     * request, ahead of EnsurePlatformAdminMfaIsEnrolledAndVerified's
     * own is_active re-check) already fails closed for a deactivated
     * admin with a 403 — this proves that panel-wide gate applies to
     * the dashboard route exactly like every other admin-panel route,
     * never a bypass carved out for the landing page.
     */
    public function test_an_inactive_admin_is_forbidden(): void
    {
        $admin = $this->activeMfaVerifiedAdmin(['is_active' => false]);

        $this->actingAs($admin, 'platform_admin')
            ->get(Dashboard::getUrl())
            ->assertForbidden();
    }

    public function test_a_never_enrolled_admin_is_redirected_to_the_mfa_set_up_required_page(): void
    {
        $admin = PlatformAdmin::factory()->create([
            'is_active' => true,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);

        $this->actingAs($admin, 'platform_admin')
            ->get(Dashboard::getUrl())
            ->assertRedirect($this->adminUrl('/multi-factor-authentication/set-up'));
    }

    /**
     * The headline requirement: unlike FirmResource/PlatformSecurityDashboardPage/
     * etc., this page is NOT role-restricted — any active, MFA-verified
     * admin with zero role grants at all still reaches it (200).
     */
    public function test_an_active_mfa_verified_admin_with_no_roles_can_reach_the_dashboard(): void
    {
        $admin = $this->activeMfaVerifiedAdmin();

        $response = $this->actingAs($admin, 'platform_admin')->get(Dashboard::getUrl());

        $response->assertOk();
    }

    /**
     * Ungated widgets (environment badge, platform & infrastructure)
     * render for a no-role admin; gated widgets (Firms, Platform
     * Administrators, Integrations, Tenant Isolation & Security, Recent
     * Privileged Activity) do not — proving canView() actually filters
     * per-widget rather than the page merely rendering everything to
     * everyone.
     */
    public function test_a_no_role_admin_sees_only_ungated_widget_content(): void
    {
        $admin = $this->activeMfaVerifiedAdmin();
        Firm::factory()->create();

        $response = $this->actingAs($admin, 'platform_admin')->get(Dashboard::getUrl());

        $response->assertOk();
        // Ungated: environment + system/infrastructure widgets.
        $response->assertSee('Environment');
        $response->assertSee('Platform &amp; Infrastructure', false);
        // Gated: the Firms widget's own heading/stat labels must not appear.
        $response->assertDontSee('Total firms');
        $response->assertDontSee('Total firm users');
    }

    public function test_a_super_admin_sees_every_widget_section(): void
    {
        $admin = $this->activeMfaVerifiedAdmin();
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);

        Firm::factory()->create();

        $response = $this->actingAs($admin, 'platform_admin')->get(Dashboard::getUrl());

        $response->assertOk();
        $response->assertSee('Environment');
        $response->assertSee('Platform &amp; Infrastructure', false);
        $response->assertSee('Total firms');
        $response->assertSee('Active administrators');
        $response->assertSee('Connected');
        $response->assertSee('Tenant Isolation &amp; Security', false);
        $response->assertSee('Recent Privileged Activity');
    }
}
