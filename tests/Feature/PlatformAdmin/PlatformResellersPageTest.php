<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformInternalSalesCommissionsPage;
use App\Filament\Pages\PlatformResellersPage;
use App\Models\CommissionEvent;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformResellersPageTest — Billing & Commercial Control Plane pass.
 *
 * This page is now "Reseller Readiness": a pure capability disclosure
 * with NO data of any kind. The internal-commission table that Phase 3
 * embedded here moved to PlatformInternalSalesCommissionsPage, and its
 * data/filter/pagination coverage moved with it to
 * PlatformInternalSalesCommissionsPageTest — this file keeps navigation
 * visibility, direct-route authorization, the disclosure itself, and
 * new positive proofs that no reseller capability and no commission
 * data are asserted here.
 */
final class PlatformResellersPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    // --- Navigation visibility ---

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformResellersPage::shouldRegisterNavigation());
    }

    public function test_navigation_is_visible_for_a_billing_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlatformResellersPage::shouldRegisterNavigation());
    }

    public function test_the_navigation_label_does_not_assert_a_reseller_product_exists(): void
    {
        $this->assertSame('Reseller Readiness', PlatformResellersPage::getNavigationLabel());
    }

    // --- Direct-route authorization ---

    public function test_guest_is_redirected_from_the_resellers_page(): void
    {
        $this->get(PlatformResellersPage::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(PlatformResellersPage::getUrl())->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformResellersPage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformResellersPage::getUrl());
        $response->assertOk();
    }

    // --- Honest disclosure ---

    public function test_the_page_honestly_discloses_no_reseller_partner_system_exists(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformResellersPage::getUrl());
        $response->assertOk();
        $response->assertSee('Reseller/partner management is not currently implemented');
        $response->assertSee('no reseller or partner account system');
    }

    public function test_the_page_states_that_internal_commissions_are_a_different_concept(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformResellersPage::getUrl());
        $response->assertOk();
        $response->assertSee('Internal sales commissions are a different thing');
        $response->assertSee('never billed to a customer');
    }

    public function test_the_page_records_what_a_real_reseller_domain_would_require(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformResellersPage::getUrl());
        $response->assertOk();
        $response->assertSee('What a real reseller domain would require');
        $response->assertSee('revenue-share');
    }

    // --- Positive proof: no fabricated reseller data, and no commission
    //     data, is rendered on this page at all ---

    public function test_no_commission_data_is_rendered_on_the_reseller_readiness_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        // A commission event exists, and is deliberately NOT shown here —
        // it belongs to the separate Internal Sales Commissions page.
        CommissionEvent::factory()->create(['amount_cents' => 45000]);

        $response = $this->get(PlatformResellersPage::getUrl());
        $response->assertOk();
        $response->assertDontSee('450.00');
        $response->assertDontSee('Commission events');
    }

    public function test_the_page_never_renders_a_fake_zero_reseller_metric(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformResellersPage::getUrl());
        $response->assertOk();
        $response->assertSee('would mean a reseller system exists and is empty');
        $response->assertSee('Nothing on this page is a metric');
    }

    /**
     * Structural proof that this page cannot display data even by
     * accident: it implements no table contract, imports no model, and
     * builds no query. Asserted against imports and code constructs
     * rather than prose, so the page's own explanatory docblock (which
     * legitimately names the concepts it is distinguishing itself from)
     * does not trip the check.
     */
    public function test_the_page_declares_no_table_and_runs_no_query(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformResellersPage.php'));

        $this->assertStringNotContainsString('implements HasTable', $source);
        $this->assertStringNotContainsString('InteractsWithTable', $source);
        $this->assertStringNotContainsString('EmbeddedTable', $source);
        $this->assertStringNotContainsString('public function table(', $source);
        $this->assertStringNotContainsString('use App\Models\CommissionEvent;', $source);
        $this->assertStringNotContainsString('use App\Models\CommissionPlan;', $source);
        $this->assertStringNotContainsString('::query()', $source);
    }

    public function test_no_filament_action_is_registered_and_no_commission_mutation_method_is_ever_called(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformResellersPage.php'));

        $this->assertStringNotContainsString('Action::make(', $source);
        $this->assertStringNotContainsString('->action(', $source);
        $this->assertStringNotContainsString('->markPaid(', $source);
        $this->assertStringNotContainsString('->reverse(', $source);
        $this->assertStringNotContainsString('use App\Services\CommissionEventService;', $source);
    }

    // --- The two domains are separate pages ---

    public function test_reseller_readiness_and_internal_sales_commissions_are_distinct_pages(): void
    {
        $this->assertNotSame(
            PlatformResellersPage::getUrl(),
            PlatformInternalSalesCommissionsPage::getUrl(),
        );
    }
}
