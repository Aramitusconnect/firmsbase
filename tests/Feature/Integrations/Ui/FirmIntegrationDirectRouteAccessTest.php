<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ListFirmIntegrations;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ViewFirmIntegration;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FirmIntegrationDirectRouteAccessTest — Checkpoint 13 (frozen-test-
 * closure-plan.md §4). The Firm-panel mirror of the SuperAdmin-side
 * IntegrationOverviewAdminAuthenticationTest: real HTTP GETs against the
 * Firm-panel integration routes DIRECTLY (bypassing any UI navigation
 * button/entitlement-hidden link), proving the auth/tenant/entitlement/
 * role gating holds at the route boundary itself — never merely because a
 * link was hidden.
 *
 * Firm panel: canonical host (app.firmsvault.test), ->login() (login route
 * '/login' on that host), default
 * `web` guard. Resource slug 'firm-integrations'.
 */
final class FirmIntegrationDirectRouteAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // Guest (no authentication at all) — redirected to the firm login.
    // ------------------------------------------------------------

    public function test_guest_is_redirected_to_firm_login_from_the_list_route(): void
    {
        $this->get(ListFirmIntegrations::getUrl())->assertRedirect($this->firmAppUrl('/login'));
    }

    public function test_guest_is_redirected_to_firm_login_from_the_view_route(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);

        $this->get(ViewFirmIntegration::getUrl(['record' => $connection->uuid]))->assertRedirect($this->firmAppUrl('/login'));
    }

    // ------------------------------------------------------------
    // Wrong-guard identity: a real, authenticated PlatformAdmin (on the
    // `platform_admin` guard, never `web`) is NOT a firm user — the firm
    // panel must treat it as unauthenticated.
    // ------------------------------------------------------------

    public function test_a_platform_admin_on_the_wrong_guard_is_redirected_to_firm_login_from_the_list_route(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')
            ->get(ListFirmIntegrations::getUrl())
            ->assertRedirect($this->firmAppUrl('/login'));
    }

    public function test_a_platform_admin_on_the_wrong_guard_is_redirected_to_firm_login_from_the_view_route(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewFirmIntegration::getUrl(['record' => $connection->uuid]))
            ->assertRedirect($this->firmAppUrl('/login'));
    }

    // ------------------------------------------------------------
    // Entitlement gate at the route boundary.
    // ------------------------------------------------------------

    public function test_a_disentitled_firm_owner_is_forbidden_on_the_list_route(): void
    {
        $firm = Firm::factory()->create(); // deliberately NOT entitled
        $firmUser = $this->firmUser($firm, FirmUserRole::FirmOwner);

        $this->actingAs($firmUser->user)
            ->get(ListFirmIntegrations::getUrl())
            ->assertForbidden();
    }

    // ------------------------------------------------------------
    // Role gate at the route boundary — a below-ceiling role cannot reach
    // the list even by hitting the URL directly.
    // ------------------------------------------------------------

    public function test_a_below_ceiling_role_is_forbidden_on_the_list_route(): void
    {
        $firm = $this->entitledFirm();
        $firmUser = $this->firmUser($firm, FirmUserRole::Receptionist);

        $this->actingAs($firmUser->user)
            ->get(ListFirmIntegrations::getUrl())
            ->assertForbidden();
    }

    // ------------------------------------------------------------
    // Tenant gate at the route boundary — a firm B user cannot view firm
    // A's connection by hitting its URL directly (RLS excludes the row for
    // the wrong firm's context; the page 404s rather than cross-firm leaks).
    // ------------------------------------------------------------

    public function test_a_cross_firm_actor_cannot_view_another_firms_connection_via_the_direct_route(): void
    {
        $firmA = $this->entitledFirm();
        $connectionA = $this->connectionFor($firmA);

        $firmB = $this->entitledFirm();
        $ownerB = $this->firmUser($firmB, FirmUserRole::FirmOwner);

        $response = $this->actingAs($ownerB->user)
            ->get(ViewFirmIntegration::getUrl(['record' => $connectionA->uuid]));

        $this->assertNotSame(200, $response->getStatusCode(), 'A firm B user must never successfully render firm A\'s connection detail via a direct route hit.');
        $response->assertNotFound();
    }

    // ------------------------------------------------------------
    // Positive control — a genuinely entitled, sufficiently-privileged
    // firm owner CAN reach the list route (so the denials above are not
    // merely "everything 403s/redirects").
    // ------------------------------------------------------------

    public function test_an_entitled_firm_owner_can_reach_the_list_route(): void
    {
        $firm = $this->entitledFirm();
        $firmUser = $this->firmUser($firm, FirmUserRole::FirmOwner);

        $this->actingAs($firmUser->user)
            ->get(ListFirmIntegrations::getUrl())
            ->assertOk();
    }

    public function test_an_entitled_firm_owner_can_reach_the_view_route_for_its_own_connection(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $firmUser = $this->firmUser($firm, FirmUserRole::FirmOwner);

        $this->actingAs($firmUser->user)
            ->get(ViewFirmIntegration::getUrl(['record' => $connection->uuid]))
            ->assertOk();
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

    private function firmUser(Firm $firm, FirmUserRole $role): FirmUser
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );
    }
}
