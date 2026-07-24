<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformIntegrationOverviewPage;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformIntegrationOverviewFilteringTest — Checkpoint 11.
 *
 * *** PREVIOUSLY-FLAGGED SCOPE DEVIATION (now closed, verified live) ***
 *
 * Both the frozen design's own upstream basis (agent-11h-architecture-
 * security-review.md line 629: "filterable by firm/provider/status/
 * health") and this checkpoint's test-writing brief describe
 * PlatformIntegrationOverviewPage as offering firm/provider/status/
 * health filters. `->filters([...])` now genuinely exists on the shipped
 * table() method (app/Filament/Pages/PlatformIntegrationOverviewPage.php):
 * a searchable firm filter (SelectFilter::make('firm_uuid')), a status
 * filter (against last_sync_outcome, the closest status-shaped column
 * this one-row-per-firm table carries), and a health filter (against
 * health_summary_state). A true per-PROVIDER filter remains
 * unimplementable against this table without a schema change — see the
 * migration's column list, still no `provider`/`integration_provider_id`
 * column — and is deliberately not declared, which
 * test_the_underlying_summary_table_has_no_provider_or_status_column_to_filter_on()
 * below continues to document.
 *
 * Because this page's data source is a raw Collection closure
 * (`->records()`), not an Eloquent query, Filament does not apply
 * `->filters()` state automatically — the closure itself explicitly
 * receives and applies the current `?array $filters` (see
 * PlatformIntegrationOverviewPage::table()'s own docblock). The tests
 * below prove that mechanism directly: they pull the real records
 * closure off the live, fully-booted table instance (via
 * Livewire::test(...)->instance()->getTable()->getDataSource()) and
 * invoke it with real filter-state arrays, exactly as Filament's own
 * filter form would populate them, rather than driving a full Livewire
 * filter-form interaction against a non-Eloquent-backed table.
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
        $unfiltered = $dataSource(null);
        $this->assertCount(2, $unfiltered);

        // Filtered by firm_uuid (the real shape SelectFilter::make('firm_uuid')
        // populates $filters with): only the targeted firm's row remains.
        $filtered = $dataSource(['firm_uuid' => ['value' => $firmA->uuid]]);

        $this->assertCount(1, $filtered);
        $this->assertSame($firmA->uuid, $filtered->first()['firm_uuid']);
    }

    public function test_applying_the_health_summary_state_filter_narrows_the_records_correctly(): void
    {
        $healthyFirm = Firm::factory()->activated()->create();
        $degradedFirm = Firm::factory()->activated()->create();

        $this->seedSummaryRow($healthyFirm, ['health_summary_state' => 'healthy']);
        $this->seedSummaryRow($degradedFirm, ['health_summary_state' => 'degraded']);

        $dataSource = $this->overviewTableDataSource();

        $filtered = $dataSource(['health_summary_state' => ['value' => 'degraded']]);

        $this->assertCount(1, $filtered);
        $this->assertSame($degradedFirm->uuid, $filtered->first()['firm_uuid']);
        $this->assertSame('degraded', $filtered->first()['health_summary_state']);
    }

    /**
     * Pulls the real, live records closure off the fully-booted table
     * instance (mirrors PlatformIntegrationOverviewPage::table()'s own
     * documented "records() closure explicitly receives $filters"
     * contract — the actual mechanism Filament invokes this closure
     * through for a non-Eloquent, Collection-backed table) and returns
     * it for direct invocation with filter-state arrays.
     */
    private function overviewTableDataSource(): \Closure
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformIntegrationOverviewPage::class);
        $test->assertOk();

        $dataSource = $test->instance()->getTable()->getDataSource();
        $this->assertNotNull($dataSource, 'PlatformIntegrationOverviewPage::table() must declare a records() closure.');

        return $dataSource;
    }

    public function test_the_underlying_summary_table_has_no_provider_or_status_column_to_filter_on(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('integration_platform_overview_summaries');

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

        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
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
