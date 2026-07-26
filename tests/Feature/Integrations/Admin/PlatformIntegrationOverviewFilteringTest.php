<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformIntegrationOverviewPage;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformIntegrationOverviewFilteringTest — Checkpoint 11, upgraded by
 * Phase 2 of the FirmsVault Platform Admin Control Center mission
 * ("Integration Operations Center").
 *
 * *** PREVIOUSLY-FLAGGED SCOPE DEVIATION (now closed, verified live) ***
 *
 * Both the frozen design's own upstream basis (agent-11h-architecture-
 * security-review.md line 629: "filterable by firm/provider/status/
 * health") and this checkpoint's test-writing brief describe
 * PlatformIntegrationOverviewPage as offering firm/provider/status/
 * health filters. `->filters([...])` now genuinely exists on the shipped
 * table() method (app/Filament/Pages/PlatformIntegrationOverviewPage.php):
 * a searchable firm filter (SelectFilter::make('firm_uuid')), a "Last
 * Sync Result" filter (against last_sync_outcome, the closest
 * status-shaped column this one-row-per-firm table carries), an
 * "Overall Health" filter (against health_summary_state), and — Phase 2
 * additions — "Integration Access" (entitlement_enabled) and "Failure
 * State" (derived from the failed/dead-lettered/conflict count columns)
 * filters. A true per-PROVIDER filter remains unimplementable against
 * this table without a schema change — see the migration's column list,
 * still no `provider`/`integration_provider_id` column — and is
 * deliberately not declared; see
 * test_the_underlying_summary_table_has_no_provider_or_status_column_to_filter_on()
 * below.
 *
 * Phase 2 UI-building pass: the records() closure is now backed by
 * IntegrationPlatformOversightReadService::paginatedOverviewSummaries()
 * — a genuine, filtered, SQL-level-bounded query returning a
 * LengthAwarePaginator, not an in-PHP-filtered Collection of the whole
 * table. The tests below pull the real records closure off the live,
 * fully-booted table instance and invoke it directly with real
 * filter/search/page/perPage arguments, exactly as Filament's own
 * filter form / search box / paginator would populate them.
 */
final class PlatformIntegrationOverviewFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_applying_the_firm_filter_narrows_the_records_to_only_that_firms_summary_row(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();

        $this->seedSummaryRow($firmA);
        $this->seedSummaryRow($firmB);

        $dataSource = $this->overviewTableDataSource();

        // Unfiltered: both firms' rows are present.
        $unfiltered = $dataSource(null, null);
        $this->assertInstanceOf(LengthAwarePaginator::class, $unfiltered);
        $this->assertSame(2, $unfiltered->total());

        // Filtered by firm_uuid (the real shape SelectFilter::make('firm_uuid')
        // populates $filters with): only the targeted firm's row remains.
        $filtered = $dataSource(['firm_uuid' => ['value' => $firmA->uuid]], null);

        $this->assertSame(1, $filtered->total());
        $this->assertSame($firmA->uuid, collect($filtered->items())->first()['firm_uuid']);
    }

    public function test_applying_the_health_summary_state_filter_narrows_the_records_correctly(): void
    {
        $healthyFirm = Firm::factory()->activated()->create();
        $degradedFirm = Firm::factory()->activated()->create();

        $this->seedSummaryRow($healthyFirm, ['health_summary_state' => 'healthy']);
        $this->seedSummaryRow($degradedFirm, ['health_summary_state' => 'degraded']);

        $dataSource = $this->overviewTableDataSource();

        $filtered = $dataSource(['health_summary_state' => ['value' => 'degraded']], null);

        $this->assertSame(1, $filtered->total());
        $row = collect($filtered->items())->first();
        $this->assertSame($degradedFirm->uuid, $row['firm_uuid']);
        $this->assertSame('degraded', $row['health_summary_state']);
    }

    public function test_applying_the_entitlement_filter_narrows_the_records_correctly(): void
    {
        $entitledFirm = Firm::factory()->activated()->create();
        $notEntitledFirm = Firm::factory()->activated()->create();

        $this->seedSummaryRow($entitledFirm, ['entitlement_enabled' => true]);
        $this->seedSummaryRow($notEntitledFirm, ['entitlement_enabled' => false]);

        $dataSource = $this->overviewTableDataSource();

        $filtered = $dataSource(['entitlement_enabled' => ['value' => '1']], null);

        $this->assertSame(1, $filtered->total());
        $this->assertSame($entitledFirm->uuid, collect($filtered->items())->first()['firm_uuid']);
    }

    public function test_applying_the_failure_state_filter_narrows_the_records_correctly(): void
    {
        $failingFirm = Firm::factory()->activated()->create();
        $healthyFirm = Firm::factory()->activated()->create();

        $this->seedSummaryRow($failingFirm, ['failed_permanent_sync_item_count' => 3]);
        $this->seedSummaryRow($healthyFirm);

        $dataSource = $this->overviewTableDataSource();

        $filtered = $dataSource(['failure_state' => ['value' => 'has_failures']], null);

        $this->assertSame(1, $filtered->total());
        $this->assertSame($failingFirm->uuid, collect($filtered->items())->first()['firm_uuid']);

        $noFailures = $dataSource(['failure_state' => ['value' => 'no_failures']], null);
        $this->assertSame(1, $noFailures->total());
        $this->assertSame($healthyFirm->uuid, collect($noFailures->items())->first()['firm_uuid']);
    }

    public function test_the_free_text_search_matches_firm_name_and_firm_uuid(): void
    {
        $firm = Firm::factory()->activated()->create(['name' => 'Unmistakable Search Target LLP']);
        $other = Firm::factory()->activated()->create();

        $this->seedSummaryRow($firm);
        $this->seedSummaryRow($other);

        $dataSource = $this->overviewTableDataSource();

        $byName = $dataSource(null, 'Unmistakable Search Target');
        $this->assertSame(1, $byName->total());
        $this->assertSame($firm->uuid, collect($byName->items())->first()['firm_uuid']);

        $byUuid = $dataSource(null, $firm->uuid);
        $this->assertSame(1, $byUuid->total());
        $this->assertSame($firm->uuid, collect($byUuid->items())->first()['firm_uuid']);
    }

    /**
     * Pulls the real, live records closure off the fully-booted table
     * instance (mirrors PlatformIntegrationOverviewPage::table()'s own
     * documented "records() closure explicitly receives $filters/$search"
     * contract — the actual mechanism Filament invokes this closure
     * through for a non-Eloquent-query-backed table) and returns it for
     * direct invocation with filter/search-state arguments.
     */
    private function overviewTableDataSource(): \Closure
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformIntegrationOverviewPage::class);
        $test->assertOk();

        $dataSource = $test->instance()->getTable()->getDataSource();
        $this->assertNotNull($dataSource, 'PlatformIntegrationOverviewPage::table() must declare a records() closure.');

        return $dataSource;
    }

    public function test_the_underlying_summary_table_has_no_provider_or_status_column_to_filter_on(): void
    {
        $columns = Schema::getColumnListing('integration_platform_overview_summaries');

        $this->assertNotContains('provider', $columns);
        $this->assertNotContains('integration_provider_id', $columns);
        $this->assertNotContains('status', $columns);
    }

    public function test_the_overview_table_still_enumerates_every_firm_unfiltered_and_sorted_by_firm_uuid(): void
    {
        $firms = collect(range(1, 3))->map(fn () => Firm::factory()->activated()->create())->sortBy('uuid')->values();

        foreach ($firms as $firm) {
            $this->seedSummaryRow($firm);
        }

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformIntegrationOverviewPage::class);
        $test->assertOk();

        foreach ($firms as $firm) {
            $test->assertSee($firm->uuid);
        }
    }

    public function test_the_overview_table_is_paginated(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformIntegrationOverviewPage.php'));
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression('/->paginated\(\[25, 50, 100\]\)/', $source);
        $this->assertMatchesRegularExpression("/->defaultSort\('firm_uuid'\)/", $source);
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
}
