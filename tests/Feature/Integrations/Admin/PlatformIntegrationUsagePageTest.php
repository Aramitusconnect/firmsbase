<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformIntegrationUsagePage;
use App\Integrations\Data\SanitizedUsageMetadataReference;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Services\IntegrationUsageRecorderService;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformOverviewSummaryService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformIntegrationUsagePageTest — Phase 2 (FirmsVault Platform Admin
 * Control Center, "Integration Operations Center").
 *
 * CORRECTED during Checkpoint 6's cross-provider ops review: this
 * class's own original "honest empty state" proof is still real and
 * still tested below (genuinely no usage recorded yet), but the page no
 * longer ALWAYS renders that state — `ProviderRequestExecutor::send()`
 * now calls `IntegrationUsageRecorderService::recordOnce()` for every
 * provider call, so real rows exist once any provider call has ever
 * been made. This file now also proves the page surfaces genuine
 * recorded usage when it exists (see
 * test_a_super_admin_sees_real_recorded_usage_when_it_exists below).
 */
final class PlatformIntegrationUsagePageTest extends TestCase
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
    // (a plain Filament\Pages\Page, unlike a Resource, needs its own
    // shouldRegisterNavigation() override tied to canAccess() — see
    // this class's own implementation, mirroring
    // PlatformIntegrationOverviewPage/PlatformProviderHealthPage's
    // identical established pattern.)

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformIntegrationUsagePage::shouldRegisterNavigation());
    }

    public function test_navigation_is_hidden_for_a_platform_admin_with_no_role(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformIntegrationUsagePage::shouldRegisterNavigation());
    }

    public function test_navigation_is_visible_for_an_eligible_platform_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlatformIntegrationUsagePage::shouldRegisterNavigation());
    }

    public function test_guest_is_redirected_from_the_usage_page(): void
    {
        $this->get(PlatformIntegrationUsagePage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(PlatformIntegrationUsagePage::getUrl())->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $this->actingAs($admin, 'platform_admin')->get(PlatformIntegrationUsagePage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_page_and_sees_the_honest_empty_state(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformIntegrationUsagePage::getUrl());
        $response->assertOk();

        // Genuinely no usage recorded in this test's fresh database — an
        // honest empty state, not a fabricated "$0".
        $response->assertSee('No usage has been recorded yet');

        // The proxy section is clearly labeled as NOT usage.
        $response->assertSee('Sync Volume Snapshot (not usage)');
    }

    /**
     * CORRECTED during Checkpoint 6's cross-provider ops review: proves
     * the page surfaces genuine recorded usage — via
     * IntegrationPlatformOversightReadService::usageRecordSummaryAcrossFirms() —
     * once ProviderRequestExecutor::send() has actually recorded a row,
     * closing the gap where this page always showed a "not wired up"
     * banner regardless of real data.
     */
    public function test_a_super_admin_sees_real_recorded_usage_when_it_exists(): void
    {
        $firm = Firm::factory()->create();
        app(IntegrationPlatformOverviewSummaryService::class)->refreshForFirm($firm);

        $provider = IntegrationProvider::query()->where('code', ProviderKey::Test->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Test->value]);

        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($provider)
            ->create(['external_account_id' => null]));

        $this->runWithFirmContext($firm, fn () => app(IntegrationUsageRecorderService::class)->recordOnce(
            firmId: $firm->id,
            firmIntegrationId: $connection->id,
            providerKey: ProviderKey::Test->value,
            capability: 'SupportsPushSyncContract',
            operationType: 'push',
            direction: SyncDirection::Outbound,
            resourceType: null,
            unit: 'call',
            outcome: 'success',
            idempotencyKey: 'push_operation:usage-page-test-1',
            metadata: new SanitizedUsageMetadataReference([]),
        ));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformIntegrationUsagePage::getUrl());
        $response->assertOk();

        $response->assertSee('Total recorded usage units: 1');
        $response->assertSee('Firms with recorded usage: 1');
        $response->assertSee(ProviderKey::Test->value);
        $response->assertDontSee('No usage has been recorded yet');
    }

    public function test_the_page_never_fabricates_a_usage_figure_or_labels_the_proxy_as_usage(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformIntegrationUsagePage.php'));

        // The proxy section's own label explicitly disclaims "usage".
        $this->assertStringContainsString('Sync Volume Snapshot (not usage)', $source);
        // No hardcoded/fabricated numeric literal is ever presented as a
        // usage total — every number is derived via sprintf('%d', ...)
        // against a real Collection method (count()/sum()), never a
        // literal constant.
        $this->assertMatchesRegularExpression('/sprintf\(.*%d.*\$summaries->(count|sum)\(/s', $source);
    }

    /**
     * `integration_usage_records` is legitimately NAMED in this page's
     * own docblock prose (explaining WHY it is not queried) — the
     * reliable structural signal is that no live query is ever built
     * against it: no `DB::table('integration_usage_records')` call and
     * no `IntegrationUsageRecord::query(`/`IntegrationUsageRecord::`
     * class usage (which would require a real `use` import to compile
     * at all).
     */
    public function test_the_page_never_queries_integration_usage_records_directly(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformIntegrationUsagePage.php'));

        $this->assertStringNotContainsString("DB::table('integration_usage_records')", $source);
        $this->assertStringNotContainsString('use App\Integrations\Models\IntegrationUsageRecord;', $source);
        $this->assertStringNotContainsString('IntegrationUsageRecord::', $source);
    }
}
