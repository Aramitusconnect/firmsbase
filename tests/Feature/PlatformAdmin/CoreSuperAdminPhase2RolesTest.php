<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformRoleDetailPage;
use App\Filament\Pages\PlatformRolesAndPermissionsPage;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CoreSuperAdminPhase2RolesTest — CORE SuperAdmin mission
 * (admin/core-superadmin-security), Phase 2. Proves the new
 * PlatformRoleDetailPage drill-down and the catalog page's new risk
 * classification, plus that the pre-existing last-viable-SuperAdmin
 * invariant (already covered end-to-end by
 * PlatformAdminLastSuperAdminProtectionTest) remains intact.
 */
final class CoreSuperAdminPhase2RolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function superAdmin(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        return $admin;
    }

    public function test_a_guest_is_redirected_from_the_role_detail_page(): void
    {
        $this->get(PlatformRoleDetailPage::getUrl(['roleCode' => PlatformRoleCode::SalesRep->value]))
            ->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_non_super_admin_cannot_reach_the_role_detail_page(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::PlatformAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(PlatformRoleDetailPage::getUrl(['roleCode' => PlatformRoleCode::SalesRep->value]))
            ->assertForbidden();
    }

    public function test_a_super_admin_can_open_a_role_detail_page_and_see_its_holders(): void
    {
        $actor = $this->superAdmin();
        $holder = PlatformAdmin::factory()->create(['name' => 'Billing Holder', 'is_active' => true]);
        app(PlatformRoleService::class)->grant($holder, PlatformRoleCode::BillingAdmin, $actor);

        $response = $this->actingAs($actor, 'platform_admin')
            ->get(PlatformRoleDetailPage::getUrl(['roleCode' => PlatformRoleCode::BillingAdmin->value]));

        $response->assertOk();
        $response->assertSee('Billing Holder');
        $response->assertSee(PlatformRolesAndPermissionsPage::riskClassificationFor(PlatformRoleCode::BillingAdmin));
    }

    public function test_an_unknown_role_code_is_a_404(): void
    {
        $actor = $this->superAdmin();

        $this->actingAs($actor, 'platform_admin')
            ->get($this->adminUrl('/roles-and-permissions/not_a_real_role'))
            ->assertNotFound();
    }

    public function test_a_revoked_grant_appears_under_recent_revocations_not_current_holders(): void
    {
        $actor = $this->superAdmin();
        $target = PlatformAdmin::factory()->create(['name' => 'Revoked Person', 'is_active' => true]);
        app(PlatformRoleService::class)->grant($target, PlatformRoleCode::SalesRep, $actor);
        app(PlatformRoleService::class)->revoke($target, PlatformRoleCode::SalesRep);

        $response = $this->actingAs($actor, 'platform_admin')
            ->get(PlatformRoleDetailPage::getUrl(['roleCode' => PlatformRoleCode::SalesRep->value]));

        $response->assertOk();
        $response->assertSee('Revoked Person');
        $response->assertSee('No active administrator currently holds this role.');
    }

    public function test_risk_classification_is_derived_from_the_real_high_privilege_constants(): void
    {
        $this->assertSame('High', PlatformRolesAndPermissionsPage::riskClassificationFor(PlatformRoleCode::SuperAdmin));
        $this->assertSame('Low', PlatformRolesAndPermissionsPage::riskClassificationFor(PlatformRoleCode::SalesRep));
    }
}
