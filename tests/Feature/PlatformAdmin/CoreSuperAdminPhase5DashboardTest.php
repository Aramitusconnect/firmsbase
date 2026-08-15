<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\Dashboard;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * CoreSuperAdminPhase5DashboardTest — CORE SuperAdmin mission
 * (admin/core-superadmin-security), Phase 5. Proves the new
 * "Requires Attention" widget (PlatformRequiresAttentionWidget):
 * it renders first on the Executive Dashboard, shows the honest empty
 * state when nothing this codebase can measure is actually wrong, and
 * surfaces real signals (MFA-enrollment gap, sole-active-SuperAdmin)
 * derived purely from PlatformExecutiveDashboardService::snapshot() —
 * with severity always rendered as a visible word, never color-only.
 */
final class CoreSuperAdminPhase5DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

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

    public function test_the_widget_renders_first_on_the_dashboard(): void
    {
        $admin = $this->activeMfaVerifiedAdmin();
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(Dashboard::getUrl());

        $response->assertOk();
        $response->assertSee('Requires Attention');
    }

    /**
     * Two active SuperAdmins (both MFA-confirmed) and no Firms at all —
     * every signal this widget knows how to compute is clean, so it
     * must show the honest empty-state sentence, matching the mission's
     * own explicit prohibition on ever claiming "Everything is secure."
     */
    public function test_the_empty_state_renders_when_nothing_requires_attention(): void
    {
        $admin = $this->activeMfaVerifiedAdmin();
        $second = $this->activeMfaVerifiedAdmin();
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);
        $this->roleService()->grant($second, PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(Dashboard::getUrl());

        $response->assertOk();
        $response->assertSee('No core platform administration or security issues currently require attention.');
    }

    public function test_an_mfa_enrollment_gap_is_surfaced_with_a_visible_warning_word(): void
    {
        $admin = $this->activeMfaVerifiedAdmin();
        $second = $this->activeMfaVerifiedAdmin();
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);
        $this->roleService()->grant($second, PlatformRoleCode::SuperAdmin);

        // A third admin with no confirmed MFA — a genuine enrollment gap.
        PlatformAdmin::factory()->create([
            'is_active' => true,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);

        $response = $this->actingAs($admin, 'platform_admin')->get(Dashboard::getUrl());

        $response->assertOk();
        $response->assertSee('have not confirmed MFA enrollment');
        $response->assertSee('Warning');
        $response->assertDontSee('No core platform administration or security issues currently require attention.');
    }

    public function test_a_sole_active_super_admin_is_flagged_as_a_warning(): void
    {
        $admin = $this->activeMfaVerifiedAdmin();
        $this->roleService()->grant($admin, PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(Dashboard::getUrl());

        $response->assertOk();
        $response->assertSee('Only one active SuperAdmin exists on this platform.');
        $response->assertSee('Warning');
    }

    /**
     * A no-role admin cannot see the platform-admin/security-gated
     * signals at all (the widget's items() short-circuits per-section
     * exactly like every gated section in the snapshot itself) — so it
     * must fall back to the honest empty state rather than leaking a
     * signal the admin has no authorization to know about.
     */
    public function test_an_unauthorized_admin_sees_the_empty_state_not_gated_signals(): void
    {
        $admin = $this->activeMfaVerifiedAdmin();

        $response = $this->actingAs($admin, 'platform_admin')->get(Dashboard::getUrl());

        $response->assertOk();
        $response->assertSee('No core platform administration or security issues currently require attention.');
        $response->assertDontSee('Only one active SuperAdmin exists');
    }
}
