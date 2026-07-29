<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\PlaidCostAlertsPage;
use App\Filament\Firm\Pages\PlaidOverviewPage;
use App\Filament\Firm\Pages\PlaidUsagePage;
use App\Filament\Firm\Widgets\PlaidFirmOverviewSummaryCardsWidget;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlaidFirmPanelNavigationAuthorizationTest — Checkpoint 7 (authorization
 * review, item 19). FOUND AND FIXED during this checkpoint:
 * PlaidOverviewPage/PlaidFirmOverviewSummaryCardsWidget,
 * PlaidUsagePage, and PlaidCostAlertsPage previously gated only on
 * "has this firm purchased Plaid" (PlaidEntitlementPolicyService), with
 * NO role check at all — any active firm user of any role, including
 * Receptionist, could reach firm billing/cost/health data. This file is
 * the missing regression coverage: none of these four classes had any
 * dedicated test before this checkpoint (confirmed via repo-wide
 * search), which is exactly how the gap went unnoticed. Mirrors
 * FirmIntegrationsNavigationAuthorizationTest's established shape for
 * the sibling, already-correct IntegrationUsagePage.
 */
final class PlaidFirmPanelNavigationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    /**
     * PlaidOverviewPage / PlaidFirmOverviewSummaryCardsWidget — health/
     * activity ceiling (FinancialIntegrationAccessPolicyService::canView()).
     */
    private const HEALTH_VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::BillingStaff,
    ];

    private const HEALTH_VIEW_DENIED_ROLES = [
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
    ];

    /**
     * PlaidUsagePage / PlaidCostAlertsPage — usage/billing-impact
     * ceiling (IntegrationAccessPolicyService::canViewUsage()) —
     * narrower than the health ceiling: no Attorney.
     */
    private const USAGE_VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::BillingStaff,
    ];

    private const USAGE_VIEW_DENIED_ROLES = [
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
    ];

    // ------------------------------------------------------------
    // PlaidOverviewPage
    // ------------------------------------------------------------

    public function test_every_health_view_ceiling_role_can_access_plaid_overview_when_entitled(): void
    {
        foreach (self::HEALTH_VIEW_ROLES as $role) {
            $firm = $this->plaidEntitledFirm();
            $this->actingAsRole($firm, $role);

            $this->assertTrue(PlaidOverviewPage::canAccess(), "Role {$role->value} must access PlaidOverviewPage.");
            $this->assertTrue(PlaidOverviewPage::shouldRegisterNavigation());
        }
    }

    public function test_roles_outside_the_health_view_ceiling_cannot_access_plaid_overview_even_when_entitled(): void
    {
        foreach (self::HEALTH_VIEW_DENIED_ROLES as $role) {
            $firm = $this->plaidEntitledFirm();
            $this->actingAsRole($firm, $role);

            $this->assertFalse(PlaidOverviewPage::canAccess(), "Role {$role->value} must NOT access PlaidOverviewPage.");
            $this->assertFalse(PlaidOverviewPage::shouldRegisterNavigation());
        }
    }

    public function test_the_overview_summary_widget_shares_the_same_health_view_ceiling(): void
    {
        $firm = $this->plaidEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);

        $this->assertFalse(PlaidFirmOverviewSummaryCardsWidget::canView());

        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertTrue(PlaidFirmOverviewSummaryCardsWidget::canView());
    }

    // ------------------------------------------------------------
    // PlaidUsagePage
    // ------------------------------------------------------------

    public function test_every_usage_view_ceiling_role_can_access_plaid_usage_when_entitled(): void
    {
        foreach (self::USAGE_VIEW_ROLES as $role) {
            $firm = $this->plaidEntitledFirm();
            $this->actingAsRole($firm, $role);

            $this->assertTrue(PlaidUsagePage::canAccess(), "Role {$role->value} must access PlaidUsagePage.");
            $this->assertTrue(PlaidUsagePage::shouldRegisterNavigation());
        }
    }

    public function test_roles_outside_the_usage_view_ceiling_cannot_access_plaid_usage_even_when_entitled(): void
    {
        foreach (self::USAGE_VIEW_DENIED_ROLES as $role) {
            $firm = $this->plaidEntitledFirm();
            $this->actingAsRole($firm, $role);

            $this->assertFalse(PlaidUsagePage::canAccess(), "Role {$role->value} must NOT access PlaidUsagePage.");
            $this->assertFalse(PlaidUsagePage::shouldRegisterNavigation());
        }
    }

    public function test_attorney_sits_in_the_health_ceiling_but_not_the_usage_ceiling(): void
    {
        // The two ceilings are genuinely disjoint — Attorney can see
        // connection health but not estimated cost/billing impact.
        $firm = $this->plaidEntitledFirm();
        $this->actingAsRole($firm, FirmUserRole::Attorney);

        $this->assertTrue(PlaidOverviewPage::canAccess());
        $this->assertFalse(PlaidUsagePage::canAccess());
    }

    // ------------------------------------------------------------
    // PlaidCostAlertsPage — same usage/billing ceiling as PlaidUsagePage
    // ------------------------------------------------------------

    public function test_every_usage_view_ceiling_role_can_access_plaid_cost_alerts_when_entitled(): void
    {
        foreach (self::USAGE_VIEW_ROLES as $role) {
            $firm = $this->plaidEntitledFirm();
            $this->actingAsRole($firm, $role);

            $this->assertTrue(PlaidCostAlertsPage::canAccess(), "Role {$role->value} must access PlaidCostAlertsPage.");
            $this->assertTrue(PlaidCostAlertsPage::shouldRegisterNavigation());
        }
    }

    public function test_roles_outside_the_usage_view_ceiling_cannot_access_plaid_cost_alerts_even_when_entitled(): void
    {
        foreach (self::USAGE_VIEW_DENIED_ROLES as $role) {
            $firm = $this->plaidEntitledFirm();
            $this->actingAsRole($firm, $role);

            $this->assertFalse(PlaidCostAlertsPage::canAccess(), "Role {$role->value} must NOT access PlaidCostAlertsPage.");
            $this->assertFalse(PlaidCostAlertsPage::shouldRegisterNavigation());
        }
    }

    // ------------------------------------------------------------
    // Entitlement-disabled: an otherwise-eligible role still sees
    // nothing — never merely greyed out.
    // ------------------------------------------------------------

    public function test_a_disentitled_firm_sees_every_plaid_admin_page_omitted_even_for_an_otherwise_eligible_role(): void
    {
        $firm = Firm::factory()->create(); // no Plaid entitlement enabled
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertFalse(PlaidOverviewPage::canAccess());
        $this->assertFalse(PlaidUsagePage::canAccess());
        $this->assertFalse(PlaidCostAlertsPage::canAccess());
        $this->assertFalse(PlaidFirmOverviewSummaryCardsWidget::canView());
    }

    private function plaidEntitledFirm(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
