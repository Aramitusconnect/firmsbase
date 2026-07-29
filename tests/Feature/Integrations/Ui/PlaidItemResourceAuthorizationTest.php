<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\PlaidItemResource\Pages\ListPlaidItems;
use App\Filament\Firm\Resources\PlaidItemResource\Pages\ViewPlaidItem;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlaidItemResourceAuthorizationTest — H6 regression coverage (found in
 * a Checkpoint 7-style authorization review pass): `PlaidItemResource`
 * previously gated on `IntegrationAccessPolicyService::canView()` — the
 * NON-financial tier (FirmOwner/Attorney/Paralegal/LegalAssistant) —
 * instead of `FinancialIntegrationAccessPolicyService::canView()` (the
 * financial tier: FirmOwner/Attorney/BillingStaff ONLY), unlike every
 * sibling Plaid admin page which already made this exact correction.
 *
 * Deliberately real, direct HTTP GETs against the Firm-panel routes
 * (mirroring `FirmIntegrationDirectRouteAccessTest`'s established
 * shape) rather than merely asserting `canAccess()`/`shouldRegisterNavigation()`
 * statically: a Filament resource page can still be reachable by a
 * direct route hit even when hidden from the nav menu, and
 * `PlaidItemResource` additionally shares its Eloquent model
 * (`FirmIntegration`) — and therefore its default Laravel Policy
 * (`FirmIntegrationPolicy`, wired to the non-financial tier) — with
 * `FirmIntegrationResource`, so the list/view routes must be proven at
 * the real HTTP boundary, not merely via the static `canAccess()` gate.
 */
final class PlaidItemResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // Financial-tier roles CAN reach both routes when entitled.
    // ------------------------------------------------------------

    private const FINANCIAL_TIER_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::BillingStaff,
    ];

    private const BELOW_FINANCIAL_TIER_ROLES = [
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
    ];

    public function test_every_financial_tier_role_can_reach_the_list_route_when_entitled(): void
    {
        foreach (self::FINANCIAL_TIER_ROLES as $role) {
            $firm = $this->plaidEntitledFirm();
            $firmUser = $this->firmUser($firm, $role);

            $this->actingAs($firmUser->user)
                ->get(ListPlaidItems::getUrl())
                ->assertOk();
        }
    }

    public function test_every_financial_tier_role_can_reach_the_view_route_when_entitled(): void
    {
        foreach (self::FINANCIAL_TIER_ROLES as $role) {
            $firm = $this->plaidEntitledFirm();
            $connection = $this->connectionFor($firm);
            $firmUser = $this->firmUser($firm, $role);

            $this->actingAs($firmUser->user)
                ->get(ViewPlaidItem::getUrl(['record' => $connection->uuid]))
                ->assertOk();
        }
    }

    // ------------------------------------------------------------
    // Roles below the financial tier are forbidden on BOTH routes, even
    // when the firm is fully entitled — a direct route hit, not merely
    // a hidden nav item.
    // ------------------------------------------------------------

    public function test_roles_outside_the_financial_tier_are_forbidden_on_the_list_route_even_when_entitled(): void
    {
        foreach (self::BELOW_FINANCIAL_TIER_ROLES as $role) {
            $firm = $this->plaidEntitledFirm();
            $firmUser = $this->firmUser($firm, $role);

            $this->actingAs($firmUser->user)
                ->get(ListPlaidItems::getUrl())
                ->assertForbidden();
        }
    }

    public function test_roles_outside_the_financial_tier_are_forbidden_on_the_view_route_even_when_entitled(): void
    {
        foreach (self::BELOW_FINANCIAL_TIER_ROLES as $role) {
            $firm = $this->plaidEntitledFirm();
            $connection = $this->connectionFor($firm);
            $firmUser = $this->firmUser($firm, $role);

            // PlaidItemResource::getEloquentQuery()'s defense-in-depth
            // gate (H6 fix) makes the row itself invisible to a
            // wrong-tier actor's query, so ViewRecord::resolveRecord()
            // 404s BEFORE canAccess()/canView() are ever reached — a
            // real, safe denial (never leaks whether the record exists),
            // just a different status code than the list route's 403.
            $response = $this->actingAs($firmUser->user)
                ->get(ViewPlaidItem::getUrl(['record' => $connection->uuid]));

            $this->assertNotSame(200, $response->getStatusCode(), "Role {$role->value} must never successfully render a Plaid item detail via a direct route hit.");
            $response->assertNotFound();
        }
    }

    // ------------------------------------------------------------
    // Entitlement gate still applies even to an otherwise-eligible
    // financial-tier role (BillingStaff) — never merely greyed out for
    // a disentitled firm.
    // ------------------------------------------------------------

    public function test_a_disentitled_billing_staff_is_forbidden_on_the_list_route(): void
    {
        $firm = Firm::factory()->create(); // deliberately NOT entitled for Plaid
        $firmUser = $this->firmUser($firm, FirmUserRole::BillingStaff);

        $this->actingAs($firmUser->user)
            ->get(ListPlaidItems::getUrl())
            ->assertForbidden();
    }

    public function test_a_disentitled_firm_owner_is_forbidden_on_the_view_route(): void
    {
        $firm = Firm::factory()->create(); // deliberately NOT entitled for Plaid
        $connection = $this->connectionFor($firm);
        $firmUser = $this->firmUser($firm, FirmUserRole::FirmOwner);

        // See test_roles_outside_the_financial_tier_are_forbidden_on_the_view_route_even_when_entitled()'s
        // comment: the getEloquentQuery() defense-in-depth gate makes
        // this a 404, not a 403 — still a genuine, safe denial.
        $response = $this->actingAs($firmUser->user)
            ->get(ViewPlaidItem::getUrl(['record' => $connection->uuid]));

        $this->assertNotSame(200, $response->getStatusCode());
        $response->assertNotFound();
    }

    // ------------------------------------------------------------
    // Tenant gate — a firm B user cannot view firm A's Plaid item via a
    // direct route hit either.
    // ------------------------------------------------------------

    public function test_a_cross_firm_actor_cannot_view_another_firms_plaid_item_via_the_direct_route(): void
    {
        $firmA = $this->plaidEntitledFirm();
        $connectionA = $this->connectionFor($firmA);

        $firmB = $this->plaidEntitledFirm();
        $ownerB = $this->firmUser($firmB, FirmUserRole::FirmOwner);

        $response = $this->actingAs($ownerB->user)
            ->get(ViewPlaidItem::getUrl(['record' => $connectionA->uuid]));

        $this->assertNotSame(200, $response->getStatusCode(), 'A firm B user must never successfully render firm A\'s Plaid item detail via a direct route hit.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function plaidEntitledFirm(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function plaidProviderRow(): IntegrationProvider
    {
        return IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->firstOrFail();
    }

    private function connectionFor(Firm $firm): FirmIntegration
    {
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()
                ->forFirm($firm)
                ->forProvider($this->plaidProviderRow())
                ->create(['external_account_id' => 'ext-item-fixture']),
        );
    }

    private function firmUser(Firm $firm, FirmUserRole $role): FirmUser
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );
    }
}
