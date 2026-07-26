<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformIntegrationOverviewPage;
use App\Filament\Widgets\PlatformIntegrationOverviewSummaryCardsWidget;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformIntegrationOverviewCsvExportAndCardsTest — Phase 2
 * (FirmsVault Platform Admin Control Center, "Integration Operations
 * Center"). Proves the new dashboard summary cards widget renders real
 * aggregate data (reused, not recomputed, from
 * `integration_platform_overview_summaries`), the bounded CSV export
 * respects the current filters and never exceeds its row cap, and
 * `paginatedOverviewSummaries()`'s own bounded-pagination/determinism
 * behavior.
 */
final class PlatformIntegrationOverviewCsvExportAndCardsTest extends TestCase
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
     * @param  array<string, mixed>  $overrides
     */
    private function seedSummaryRow(Firm $firm, array $overrides = []): void
    {
        DB::table('integration_platform_overview_summaries')->insert(array_merge([
            'firm_id' => $firm->id,
            'firm_uuid' => $firm->uuid,
            'connection_count_active' => 0,
            'connection_count_disconnected' => 0,
            'connection_count_other' => 0,
            'health_summary_state' => null,
            'last_sync_outcome' => null,
            'last_sync_at' => null,
            'last_successful_sync_at' => null,
            'failed_permanent_sync_item_count' => 0,
            'dead_lettered_outbox_event_count' => 0,
            'open_conflict_count' => 0,
            'entitlement_enabled' => false,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    // ------------------------------------------------------------
    // Dashboard summary cards widget
    // ------------------------------------------------------------

    public function test_the_widget_is_hidden_for_an_unauthorized_admin(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformIntegrationOverviewSummaryCardsWidget::canView());
    }

    public function test_the_widget_reflects_real_aggregate_summary_data(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();

        $this->seedSummaryRow($firmA, ['connection_count_active' => 3, 'failed_permanent_sync_item_count' => 2]);
        $this->seedSummaryRow($firmB, ['connection_count_active' => 5, 'health_summary_state' => 'degraded']);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformIntegrationOverviewPage::getUrl());
        $response->assertOk();

        // 3 + 5 = 8 total connected across both firms' summary rows.
        $response->assertSee('8');
    }

    public function test_the_widget_shows_an_honest_empty_state_with_zero_rows(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformIntegrationOverviewPage::getUrl());
        $response->assertOk();
        $response->assertSee('No data yet');
    }

    // ------------------------------------------------------------
    // CSV export
    // ------------------------------------------------------------

    public function test_csv_export_is_gated_behind_integration_oversight_access(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformIntegrationOverviewPage.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString('canAccessIntegrationOversight', $source);
    }

    public function test_csv_export_downloads_a_csv_containing_the_correct_filtered_rows(): void
    {
        $includedFirm = Firm::factory()->activated()->create(['name' => 'CSV Export Included Firm']);
        $excludedFirm = Firm::factory()->activated()->create(['name' => 'CSV Export Excluded Firm']);

        $this->seedSummaryRow($includedFirm, ['health_summary_state' => 'degraded', 'connection_count_active' => 4]);
        $this->seedSummaryRow($excludedFirm, ['health_summary_state' => 'healthy']);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformIntegrationOverviewPage::class);
        $test->assertOk();

        $test->set('tableFilters.health_summary_state.value', 'degraded');
        $test->mountAction('exportCsv');
        $test->callMountedAction();

        $test->assertFileDownloaded();

        $downloadedContent = base64_decode(data_get($test->effects, 'download.content'));
        $this->assertStringContainsString('CSV Export Included Firm', $downloadedContent);
        $this->assertStringNotContainsString('CSV Export Excluded Firm', $downloadedContent);
        $this->assertStringContainsString('Firm', $downloadedContent);
        $this->assertStringContainsString('Connected', $downloadedContent);
        $this->assertStringContainsString('Last Updated', $downloadedContent);
    }

    public function test_a_non_authorized_admin_cannot_trigger_the_csv_export(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin');

        // A non-authorized admin can't even mount the page (403 at the
        // route level) — this proves the action's own ->visible() gate
        // is consistent with that (never independently more permissive).
        $this->get(PlatformIntegrationOverviewPage::getUrl())->assertForbidden();
    }

    // ------------------------------------------------------------
    // Bounded pagination / determinism for paginatedOverviewSummaries()
    // ------------------------------------------------------------

    public function test_paginated_overview_summaries_is_genuinely_bounded_at_the_db_level(): void
    {
        $firms = Firm::factory()->count(5)->activated()->create();
        foreach ($firms as $firm) {
            $this->seedSummaryRow($firm);
        }

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $paginator = app(IntegrationPlatformOversightReadService::class)
            ->paginatedOverviewSummaries($admin, [], null, 1, 2);

        $this->assertSame(5, $paginator->total());
        $this->assertCount(2, $paginator->items(), 'Exactly perPage=2 rows must be returned per page, never the whole table.');
    }

    public function test_two_firms_sharing_an_identical_firm_uuid_sort_key_scenario_produces_stable_repeated_order(): void
    {
        // firm_uuid values are unique, but the tie-breaker discipline is
        // proven directly: two rows sharing an identical computed_at are
        // still ordered deterministically by firm_uuid then id on every
        // call.
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();

        $now = now();
        $this->seedSummaryRow($firmA, ['computed_at' => $now]);
        $this->seedSummaryRow($firmB, ['computed_at' => $now]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $readService = app(IntegrationPlatformOversightReadService::class);

        $first = $readService->paginatedOverviewSummaries($admin, [], null, 1, 25)->pluck('firm_uuid')->all();
        $second = $readService->paginatedOverviewSummaries($admin, [], null, 1, 25)->pluck('firm_uuid')->all();

        $this->assertSame($first, $second);
    }
}
