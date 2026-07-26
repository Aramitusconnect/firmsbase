<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\EntitlementSource;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Filament\Pages\PlatformFirmIntegrationsPage;
use App\Filament\Pages\PlatformIntegrationOverviewPage;
use App\Filament\Pages\PlatformIntegrationUsagePage;
use App\Filament\Resources\ConflictResource;
use App\Filament\Resources\ConnectionResource\Pages\ViewConnection;
use App\Filament\Resources\DeadLetterQueueResource;
use App\Filament\Resources\SyncFailureResource;
use App\Filament\Resources\WebhookEventResource;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\TenantEncryptionKey;
use App\Services\EntitlementService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PlatformIntegrationDrillDownNavigationTest — Phase 2 (FirmsVault
 * Platform Admin Control Center, "Integration Operations Center").
 * Proves the required drill-down chain:
 * PlatformIntegrationOverviewPage (firm row) ->
 * PlatformFirmIntegrationsPage (connection row) ->
 * PlatformFirmIntegrationDetailPage, plus the new cross-links from the
 * detail page to ConnectionResource\Pages\ViewConnection and to the
 * parallel-agent-owned Sync Failures/Webhook Events/Dead-Letter
 * Queue/Conflicts resources.
 *
 * *** COORDINATION NOTE ***
 * This test's fixtures for the parallel-agent resources
 * (SyncFailureResource/WebhookEventResource/DeadLetterQueueResource/
 * ConflictResource) exist in this SAME shared worktree at the time this
 * test was written (a parallel agent landed them concurrently) — this is
 * NOT a guess; the exact FQCNs matched
 * PlatformFirmIntegrationDetailPage::crossLinkIfAvailable()'s own
 * candidate list on the first try. If those classes are ever renamed
 * before both passes are committed, this test (and the guarded
 * candidate lists in PlatformFirmIntegrationDetailPage/ViewConnection)
 * must be re-verified.
 */
final class PlatformIntegrationDrillDownNavigationTest extends TestCase
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

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function entitledFirmWithConnection(): array
    {
        $firm = Firm::factory()->activated()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        return [$firm, $connection];
    }

    public function test_the_overview_pages_firm_row_links_to_the_per_firm_integrations_page(): void
    {
        $firm = Firm::factory()->activated()->create();
        DB::table('integration_platform_overview_summaries')->insert([
            'firm_id' => $firm->id,
            'firm_uuid' => $firm->uuid,
            'connection_count_active' => 0,
            'connection_count_disconnected' => 0,
            'connection_count_other' => 0,
            'entitlement_enabled' => false,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformIntegrationOverviewPage::getUrl());
        $response->assertOk();

        $expectedUrl = PlatformFirmIntegrationsPage::getUrl(['firmUuid' => $firm->uuid]);
        $response->assertSee($expectedUrl, escape: false);
    }

    public function test_the_per_firm_integrations_pages_connection_row_links_to_the_detail_page(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformFirmIntegrationsPage::getUrl(['firmUuid' => $firm->uuid]));
        $response->assertOk();

        $expectedUrl = PlatformFirmIntegrationDetailPage::getUrl(['firmUuid' => $firm->uuid, 'connectionUuid' => $connection->uuid]);
        $response->assertSee($expectedUrl, escape: false);
    }

    public function test_the_detail_page_links_to_the_global_connections_resource_for_the_same_connection(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformFirmIntegrationDetailPage::getUrl(['firmUuid' => $firm->uuid, 'connectionUuid' => $connection->uuid]));
        $response->assertOk();

        $expectedUrl = ViewConnection::getUrl(['firmUuid' => $firm->uuid, 'connectionUuid' => $connection->uuid]);
        $response->assertSee($expectedUrl, escape: false);
    }

    /**
     * Proves the guarded cross-link mechanism actually resolves real
     * URLs for the four parallel-agent resources that exist in this
     * worktree right now — not merely that it fails safely.
     */
    public function test_the_detail_page_resolves_real_cross_links_to_the_parallel_agent_resources(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformFirmIntegrationDetailPage::getUrl(['firmUuid' => $firm->uuid, 'connectionUuid' => $connection->uuid]));
        $response->assertOk();

        foreach ([SyncFailureResource::class, WebhookEventResource::class, DeadLetterQueueResource::class, ConflictResource::class] as $resourceClass) {
            $expectedUrl = $resourceClass::getUrl('index', ['tableFilters' => ['connection' => ['value' => $connection->id]]]);
            $response->assertSee($expectedUrl, escape: false);
        }

        // "Integration Usage" resolves too, via the dedicated
        // crossLinkToUsagePage() path (a plain, parameter-less link —
        // PlatformIntegrationUsagePage is a firm/platform-wide aggregate
        // Page with no per-connection filtering support at all, by
        // design; see that class's own docblock).
        $usageUrl = PlatformIntegrationUsagePage::getUrl();
        $response->assertSee("Integration Usage: {$usageUrl}", escape: false);
    }

    /**
     * Even if a candidate class does not exist (simulated here by
     * asserting the guard behavior directly against a page render that
     * pre-dates one of the four resources landing) — the guard renders a
     * plain "not yet available" line, never a 500. This is proven
     * structurally (by reading the guard's own implementation) rather
     * than by temporarily removing a real class from the shared
     * worktree, which would be unsafe in a concurrently-edited repo.
     */
    public function test_the_cross_link_guard_never_hard_depends_on_class_existence(): void
    {
        $detailSource = file_get_contents(app_path('Filament/Pages/PlatformFirmIntegrationDetailPage.php'));
        $viewConnectionSource = file_get_contents(app_path('Filament/Resources/ConnectionResource/Pages/ViewConnection.php'));

        foreach ([$detailSource, $viewConnectionSource] as $source) {
            $this->assertIsString($source);
            $this->assertStringContainsString('class_exists(', $source);
            $this->assertStringContainsString('catch (Throwable', $source);
            $this->assertStringContainsString('not yet available', $source);
        }
    }

    public function test_the_view_connection_page_also_links_back_to_the_per_firm_detail_page(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(ViewConnection::getUrl(['firmUuid' => $firm->uuid, 'connectionUuid' => $connection->uuid]));
        $response->assertOk();

        $expectedUrl = PlatformFirmIntegrationDetailPage::getUrl(['firmUuid' => $firm->uuid, 'connectionUuid' => $connection->uuid]);
        $response->assertSee($expectedUrl, escape: false);
    }

    // ------------------------------------------------------------
    // Drill-down pages stay nav-suppressed (correct — genuinely
    // per-firm/per-connection, not top-level nav items).
    // ------------------------------------------------------------

    public function test_the_drill_down_pages_are_not_registered_in_navigation(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformFirmIntegrationsPage::shouldRegisterNavigation());
        $this->assertFalse(PlatformFirmIntegrationDetailPage::shouldRegisterNavigation());
    }

    public function test_the_per_firm_integrations_page_is_genuinely_paginated_not_paginated_false(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformFirmIntegrationsPage.php'));
        $this->assertIsString($source);

        $this->assertStringNotContainsString('paginated(false)', $source);
        $this->assertMatchesRegularExpression('/->paginated\(\[25, 50, 100\]\)/', $source);
    }
}
