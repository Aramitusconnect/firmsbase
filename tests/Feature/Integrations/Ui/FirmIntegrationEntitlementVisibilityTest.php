<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\IntegrationUsagePage;
use App\Filament\Firm\Resources\FirmIntegrationResource;
use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\ConnectProviderAction;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ListFirmIntegrations;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * FirmIntegrationEntitlementVisibilityTest — Checkpoint 10 (frozen-
 * design-post-security-review.md §4 "UX-layer, non-boundary"). Dedicated
 * coverage of the non-throwing `isEnabled()` UX-layer check hiding the
 * whole feature for a disentitled firm, separate from the throwing
 * `assertEnabled()` boundary tested exhaustively elsewhere (extended
 * `ProviderConnectionServiceOAuthTest`, and every Ui action test file's
 * own "requires entitlement" cases).
 */
final class FirmIntegrationEntitlementVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // isEnabled() itself — non-throwing, returns bool, never throws
    // ------------------------------------------------------------

    public function test_is_enabled_returns_false_non_throwing_for_a_disentitled_firm(): void
    {
        $firm = Firm::factory()->create();

        $result = app(IntegrationEntitlementPolicyService::class)->isEnabled($firm);

        $this->assertFalse($result);
        $this->addToAssertionCount(1); // reaching here at all proves isEnabled() did not throw
    }

    public function test_is_enabled_returns_true_non_throwing_for_an_entitled_firm(): void
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        $this->assertTrue(app(IntegrationEntitlementPolicyService::class)->isEnabled($firm));
    }

    public function test_assert_enabled_throws_for_the_identical_disentitled_firm_isenabled_only_reports_false(): void
    {
        // The contrast this file exists to prove: the SAME disentitled
        // firm state produces a non-throwing `false` from isEnabled()
        // (UX layer) and a thrown RuntimeException from assertEnabled()
        // (the real boundary) — never the same behavior from both.
        $firm = Firm::factory()->create();
        $policy = app(IntegrationEntitlementPolicyService::class);

        $this->assertFalse($policy->isEnabled($firm));

        $this->expectException(RuntimeException::class);
        $policy->assertEnabled($firm);
    }

    // ------------------------------------------------------------
    // isEnabled() drives every UX-layer visible()/canAccess()/
    // shouldRegisterNavigation() hide-entirely decision
    // ------------------------------------------------------------

    public function test_firm_integration_resource_can_access_uses_the_non_throwing_isenabled_never_throws_for_a_disentitled_firm(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        // canAccess() must return false cleanly — never let a
        // disentitled firm's assertEnabled()-style exception propagate
        // out of a UX-layer navigation check.
        $result = FirmIntegrationResource::canAccess();

        $this->assertFalse($result);
    }

    public function test_integration_usage_page_can_access_uses_the_non_throwing_isenabled_never_throws_for_a_disentitled_firm(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $result = IntegrationUsagePage::canAccess();

        $this->assertFalse($result);
    }

    public function test_connect_provider_action_and_the_list_page_itself_are_unreachable_for_a_disentitled_firm_via_a_clean_forbidden_response_never_an_uncaught_exception(): void
    {
        $firm = Firm::factory()->create(); // disentitled
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        // The key UX property this test proves: a disentitled firm gets
        // a clean, well-formed 403 response (never an uncaught
        // exception bubbling out of an isEnabled()-style check, which
        // must NEVER throw) — mirrors this file's own contrast with the
        // throwing assertEnabled() boundary tested elsewhere.
        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListFirmIntegrations::class));

        $test->assertForbidden();
    }

    public function test_a_connected_entitled_firm_can_see_the_connect_provider_action_button_in_the_rendered_html(): void
    {
        // Positive-case contrast for the negative case above: the SAME
        // action's visible() closure, evaluated against an entitled
        // firm/eligible role, correctly renders the button.
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListFirmIntegrations::class));

        $test->assertOk();
        $test->assertSee("mountAction('".ConnectProviderAction::getDefaultName()."')", false);
    }

    // ------------------------------------------------------------
    // Re-enabling flips visibility back on for the SAME actor/session,
    // proving the check is live/current, never cached stale
    // ------------------------------------------------------------

    public function test_toggling_entitlement_off_then_on_correctly_flips_can_access_both_directions_for_the_same_actor(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertFalse(FirmIntegrationResource::canAccess());

        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        $this->assertTrue(FirmIntegrationResource::canAccess());

        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, false);
        $this->assertFalse(FirmIntegrationResource::canAccess());
    }

    // ------------------------------------------------------------
    // isEnabled() is per-firm, never leaks across firms (mirrors
    // IntegrationEntitlementPolicyServiceTest's own convention, proven
    // here specifically at the UX-visibility call sites)
    // ------------------------------------------------------------

    public function test_entitlement_visibility_is_per_firm_never_leaking_across_firms(): void
    {
        $enabledFirm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($enabledFirm, 'integration', EntitlementSource::AdminOverride, true);
        $disabledFirm = Firm::factory()->create();

        $this->actingAsRole($enabledFirm, FirmUserRole::FirmOwner);
        $this->assertTrue(FirmIntegrationResource::canAccess());

        $this->actingAsRole($disabledFirm, FirmUserRole::FirmOwner);
        $this->assertFalse(FirmIntegrationResource::canAccess());
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

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
