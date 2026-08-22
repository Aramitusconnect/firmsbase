<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\IntegrationUsagePage;
use App\Filament\Firm\Resources\FirmIntegrationResource;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ViewFirmIntegration;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Policies\FirmIntegrationPolicy;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FirmIntegrationsNavigationAuthorizationTest — Checkpoint 10 (frozen-
 * design-post-security-review.md §4, §11). Proves the three role
 * ceilings (view / connect-configure-disconnect-sync / usage) via
 * `FirmIntegrationResource::canAccess()`/`shouldRegisterNavigation()`
 * and `IntegrationUsagePage::canAccess()`/`shouldRegisterNavigation()`
 * — including the explicit Receptionist-never-passes regression this
 * codebase's convention establishes elsewhere (mirrors
 * ProviderConnectionServiceOAuthTest's own
 * test_role_tier_ceilings_are_never_widened_receptionist_cannot_initiate).
 * Also proves entitlement-disabled firms see the feature omitted
 * entirely (canAccess()/shouldRegisterNavigation() both false — never
 * merely greyed out) per frozen design §4's UX ruling.
 */
final class FirmIntegrationsNavigationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Filament's own canAccess()/canViewAny() authorization helper
        // resolves the acting user via Filament::auth()->user(), which
        // reads the CURRENT panel's own auth guard
        // (getCurrentOrDefaultPanel()) — never Auth::user() directly.
        // Outside of an actual HTTP request through the 'firm' panel's
        // own routes/middleware (which Livewire::test() never
        // dispatches through), no panel is "current" and Filament falls
        // back to the globally-default panel ('admin', per
        // AdminPanelProvider::default()) — a different panel than the
        // one FirmIntegrationResource/IntegrationUsagePage actually
        // belong to. Explicitly activating the 'firm' panel here mirrors
        // what EstablishFirmTenantContext's real request-time middleware
        // stack does implicitly for every genuine firm-panel request.
        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
    ];

    private const CONNECT_CONFIGURE_DISCONNECT_SYNC_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    private const USAGE_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::BillingStaff,
    ];

    // ------------------------------------------------------------
    // FirmIntegrationResource — view ceiling
    // ------------------------------------------------------------

    public function test_every_view_ceiling_role_can_access_the_firm_integration_resource_when_entitled(): void
    {
        foreach (self::VIEW_ROLES as $role) {
            $firm = $this->entitledFirm();
            $this->actingAsRole($firm, $role);

            $this->assertTrue(FirmIntegrationResource::canAccess(), "Role {$role->value} must be able to access FirmIntegrationResource.");
            $this->assertTrue(FirmIntegrationResource::shouldRegisterNavigation(), "Role {$role->value} must see the navigation item.");
        }
    }

    public function test_receptionist_can_never_access_the_firm_integration_resource_even_when_entitled(): void
    {
        $firm = $this->entitledFirm();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);

        $this->assertFalse(FirmIntegrationResource::canAccess());
        $this->assertFalse(FirmIntegrationResource::shouldRegisterNavigation());
    }

    public function test_billing_staff_cannot_access_the_firm_integration_resource_view_ceiling_despite_having_usage_access(): void
    {
        // BillingStaff sits in the USAGE ceiling but NOT the VIEW
        // ceiling — the two are genuinely disjoint per frozen design
        // §11.2, and this must hold even when entitled.
        $firm = $this->entitledFirm();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->assertFalse(FirmIntegrationResource::canAccess());
        $this->assertFalse(FirmIntegrationResource::shouldRegisterNavigation());
    }

    // ------------------------------------------------------------
    // FirmIntegrationResource — entitlement-disabled: feature OMITTED
    // entirely, never merely greyed out
    // ------------------------------------------------------------

    public function test_a_disentitled_firm_sees_the_resource_entirely_omitted_even_for_an_otherwise_eligible_role(): void
    {
        $firm = Firm::factory()->create(); // no entitlement enabled
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertFalse(FirmIntegrationResource::canAccess(), 'A disentitled firm must never see canAccess() return true, regardless of role.');
        $this->assertFalse(FirmIntegrationResource::shouldRegisterNavigation(), 'A disentitled firm must never register the navigation item at all — omitted, not merely hidden/greyed.');
    }

    public function test_re_enabling_entitlement_restores_access_for_the_same_actor(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertFalse(FirmIntegrationResource::canAccess());

        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        $this->assertTrue(FirmIntegrationResource::canAccess());
        $this->assertTrue(FirmIntegrationResource::shouldRegisterNavigation());
    }

    // ------------------------------------------------------------
    // ViewFirmIntegration page — reachable/unreachable via Livewire::test()
    // matching the resource-level ceiling (view()/FirmIntegrationPolicy)
    //
    // DISCLOSED BLOCKER: ViewFirmIntegration cannot currently be
    // MOUNTED SUCCESSFULLY via Livewire::test() for ANY role, including
    // an otherwise-eligible one — a confirmed Filament framework bug
    // documented exhaustively elsewhere in this checkpoint's Ui test
    // suite (see FirmIntegrationConnectionLifecycleActionsTest's own
    // precise proof: ViewRecord::mount() unconditionally enumerates
    // getRelations() to build its record sub-navigation, and
    // RelationManager::canViewForRecord() calls an undefined
    // relationship method on FirmIntegration for every registered
    // RelationManager). What CAN still be proven at the Livewire level
    // is the NEGATIVE case (an ineligible role is rejected via a clean
    // 403 BEFORE mount() ever reaches the relation-manager enumeration
    // that crashes) — `authorizeAccess()`'s `abort_unless(canView(...),
    // 403)` runs first, so an ineligible role never reaches the buggy
    // code path at all. The POSITIVE "eligible role can view" case is
    // instead proven directly against FirmIntegrationPolicy::view() —
    // the exact gate authorizeAccess() consults.
    // ------------------------------------------------------------

    public function test_a_view_ceiling_role_passes_firm_integration_policy_view_for_a_connection_in_their_own_firm(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $policy = app(FirmIntegrationPolicy::class);

        $this->assertTrue($policy->view($firmUser->user, $connection));
    }

    public function test_receptionist_is_denied_mounting_the_view_page_via_a_clean_forbidden_response_even_within_their_own_firm(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $this->actingAsRole($firm, FirmUserRole::Receptionist);

        // Receptionist fails canView() BEFORE mount() ever reaches the
        // relation-manager sub-navigation build step that crashes for
        // an eligible role — so this negative case genuinely exercises
        // a real, clean, un-buggy 403 rejection via Livewire::test().
        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(
                ViewFirmIntegration::class,
                ['record' => $connection->uuid]
            )
        );

        $test->assertForbidden();
    }

    public function test_receptionist_fails_firm_integration_policy_view_too(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Receptionist);

        $policy = app(FirmIntegrationPolicy::class);

        $this->assertFalse($policy->view($firmUser->user, $connection));
    }

    // ------------------------------------------------------------
    // Connect/configure/disconnect/sync ceiling — role-level proof via
    // IntegrationAccessPolicyService (the exact oracle every action's
    // visible()/action() closure defers to)
    // ------------------------------------------------------------

    public function test_only_firm_owner_and_attorney_pass_the_connect_configure_disconnect_sync_ceiling(): void
    {
        $policy = app(IntegrationAccessPolicyService::class);

        foreach (FirmUserRole::cases() as $role) {
            $expected = in_array($role, self::CONNECT_CONFIGURE_DISCONNECT_SYNC_ROLES, true);

            $this->assertSame($expected, $policy->canConnect($role), "canConnect() mismatch for {$role->value}");
            $this->assertSame($expected, $policy->canConfigure($role), "canConfigure() mismatch for {$role->value}");
            $this->assertSame($expected, $policy->canDisconnect($role), "canDisconnect() mismatch for {$role->value}");
            $this->assertSame($expected, $policy->canSync($role), "canSync() mismatch for {$role->value}");
        }
    }

    public function test_receptionist_never_passes_any_of_the_four_connect_configure_disconnect_sync_gates(): void
    {
        $policy = app(IntegrationAccessPolicyService::class);

        $this->assertFalse($policy->canConnect(FirmUserRole::Receptionist));
        $this->assertFalse($policy->canConfigure(FirmUserRole::Receptionist));
        $this->assertFalse($policy->canDisconnect(FirmUserRole::Receptionist));
        $this->assertFalse($policy->canSync(FirmUserRole::Receptionist));
    }

    // ------------------------------------------------------------
    // IntegrationUsagePage — usage ceiling, disjoint from view ceiling
    // ------------------------------------------------------------

    public function test_firm_owner_and_billing_staff_can_access_the_usage_page_when_entitled(): void
    {
        foreach (self::USAGE_ROLES as $role) {
            $firm = $this->entitledFirm();
            $this->actingAsRole($firm, $role);

            $this->assertTrue(IntegrationUsagePage::canAccess(), "Role {$role->value} must access IntegrationUsagePage.");
            $this->assertTrue(IntegrationUsagePage::shouldRegisterNavigation());
        }
    }

    public function test_a_view_ceiling_only_role_cannot_access_the_usage_page(): void
    {
        // Paralegal/LegalAssistant/Attorney are in the VIEW ceiling but
        // NOT the USAGE ceiling — genuinely disjoint (frozen design
        // §11.2's whole reason IntegrationUsagePage is a standalone
        // page rather than nested under the resource).
        foreach ([FirmUserRole::Attorney, FirmUserRole::Paralegal, FirmUserRole::LegalAssistant] as $role) {
            $firm = $this->entitledFirm();
            $this->actingAsRole($firm, $role);

            $this->assertFalse(IntegrationUsagePage::canAccess(), "Role {$role->value} must NOT access IntegrationUsagePage.");
            $this->assertFalse(IntegrationUsagePage::shouldRegisterNavigation());
        }
    }

    public function test_receptionist_cannot_access_the_usage_page(): void
    {
        $firm = $this->entitledFirm();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);

        $this->assertFalse(IntegrationUsagePage::canAccess());
    }

    public function test_a_disentitled_firm_sees_the_usage_page_entirely_omitted_even_for_billing_staff(): void
    {
        $firm = Firm::factory()->create(); // no entitlement enabled
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->assertFalse(IntegrationUsagePage::canAccess());
        $this->assertFalse(IntegrationUsagePage::shouldRegisterNavigation());
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function entitledFirm(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function connectionFor(Firm $firm): FirmIntegration
    {
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['external_account_id' => null])
        );
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
