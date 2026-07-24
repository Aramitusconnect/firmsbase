<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\FirmActivationStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformIntegrationOverviewPage;
use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Enums\ConflictStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Services\HealthStateService;
use App\Jobs\RefreshIntegrationPlatformOverviewSummaryJob;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformOverviewSummaryService;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PlatformIntegrationOverviewCrossFirmCorrectnessTest — Checkpoint 11
 * (frozen-design-post-security-review.md §5). Proves
 * IntegrationPlatformOverviewSummaryService's per-firm computation is
 * correct and that no firm's data leaks into another firm's row, and
 * that the overview page renders each firm's row correctly.
 *
 * *** PREVIOUSLY-DISCOVERED PRODUCTION BUG (now fixed, verified live) ***
 *
 * database/migrations/2026_09_09_090001_create_integration_platform_overview_summaries_table.php
 * previously called `$table->foreignId('firm_id')->constrained('firms')
 * ->cascadeOnDelete()->unique()` — ->unique() there silently fell through
 * to Fluent::__call() on the ForeignKeyDefinition object ->constrained()
 * returns, never creating a real unique constraint, which made
 * IntegrationPlatformOverviewSummaryService::writeSummaryRow()'s
 * `upsert([...], uniqueBy: ['firm_id'], ...)` throw SQLSTATE[42P10] on
 * every call. The migration now declares `$table->unique('firm_id')` as
 * its own explicit schema command (see the migration's own current
 * column list) — a real unique constraint exists on firm_id, confirmed
 * live against pg_constraint. test_refresh_for_firm_succeeds_and_a_second_call_for_the_same_firm_upserts_the_existing_row_instead_of_throwing()
 * below proves the fix live: a genuine double-refreshForFirm() call for
 * the same firm succeeds both times, updates the SAME row (same primary
 * key), and advances computed_at — never throwing, never inserting a
 * duplicate row.
 */
final class PlatformIntegrationOverviewCrossFirmCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_for_firm_succeeds_and_a_second_call_for_the_same_firm_upserts_the_existing_row_instead_of_throwing(): void
    {
        $firm = Firm::factory()->activated()->create();

        $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->count(2)->create());

        \Illuminate\Support\Carbon::setTestNow(now());

        app(IntegrationPlatformOverviewSummaryService::class)->refreshForFirm($firm);

        $this->assertSame(
            1,
            DB::table('integration_platform_overview_summaries')->where('firm_id', $firm->id)->count(),
            'The first refreshForFirm() call must succeed and write exactly one row for this firm.'
        );

        $firstRow = DB::table('integration_platform_overview_summaries')->where('firm_id', $firm->id)->first();
        $this->assertNotNull($firstRow);
        $this->assertSame(2, $firstRow->connection_count_active);

        // A real active connection is added between the two refreshes so
        // the second write is observably different from the first, not
        // just a no-op re-write of identical values.
        $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        \Illuminate\Support\Carbon::setTestNow(now()->addMinute());

        // The second call for the SAME firm must succeed too — no
        // duplicate-key exception — and must UPDATE the existing row
        // (same primary key) rather than inserting a second one.
        app(IntegrationPlatformOverviewSummaryService::class)->refreshForFirm($firm);

        $this->assertSame(
            1,
            DB::table('integration_platform_overview_summaries')->where('firm_id', $firm->id)->count(),
            'A second refreshForFirm() call for the same firm must update the existing row, never insert a duplicate.'
        );

        $secondRow = DB::table('integration_platform_overview_summaries')->where('firm_id', $firm->id)->first();
        $this->assertNotNull($secondRow);

        $this->assertSame(
            $firstRow->id,
            $secondRow->id,
            'The second refresh must update the SAME primary-key row as the first, not create a new one.'
        );
        $this->assertSame(3, $secondRow->connection_count_active, 'The second refresh must reflect the newly-added connection.');
        $this->assertTrue(
            \Illuminate\Support\Carbon::parse($secondRow->computed_at)->gt(\Illuminate\Support\Carbon::parse($firstRow->computed_at)),
            'computed_at must advance on the second refresh, proving it is a real update, not a stale/duplicate row.'
        );

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_the_underlying_per_firm_computation_correctly_derives_that_firms_own_data_only(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();

        $this->runWithFirmContext($firmA, function () use ($firmA) {
            FirmIntegration::factory()->forFirm($firmA)->count(2)->create();
            $disconnected = FirmIntegration::factory()->forFirm($firmA)->disconnected()->create();

            IntegrationOutboxEvent::factory()->forFirmIntegration($disconnected)->deadLettered()->count(3)->create();

            $run = IntegrationSyncRun::factory()->forFirmIntegration($disconnected)->succeeded()->create();
            IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->count(2)->create();

            IntegrationConflict::factory()->forFirmIntegration($disconnected)->create(['status' => ConflictStatus::Detected->value]);
        });

        $this->runWithFirmContext($firmB, fn () => FirmIntegration::factory()->forFirm($firmB)->create());

        $resultA = $this->computeForFirm($firmA->fresh());
        $resultB = $this->computeForFirm($firmB->fresh());

        $this->assertSame(2, $resultA['connection_count_active']);
        $this->assertSame(1, $resultA['connection_count_disconnected']);
        $this->assertSame(3, $resultA['dead_lettered_outbox_event_count']);
        $this->assertSame(2, $resultA['failed_permanent_sync_item_count']);
        $this->assertSame(1, $resultA['open_conflict_count']);

        // Firm B's own numbers must show ITS OWN zeros — none of Firm
        // A's failure/conflict counts have leaked across.
        $this->assertSame(1, $resultB['connection_count_active']);
        $this->assertSame(0, $resultB['connection_count_disconnected']);
        $this->assertSame(0, $resultB['dead_lettered_outbox_event_count']);
        $this->assertSame(0, $resultB['failed_permanent_sync_item_count']);
        $this->assertSame(0, $resultB['open_conflict_count']);
    }

    public function test_the_underlying_computation_reflects_entitlement_state_correctly(): void
    {
        $firm = Firm::factory()->activated()->create();

        $before = $this->computeForFirm($firm);
        $this->assertFalse($before['entitlement_enabled']);

        app(\App\Services\EntitlementService::class)->setForSource($firm, 'integration', \App\Enums\EntitlementSource::AdminOverride, true);

        $after = $this->computeForFirm($firm->fresh());
        $this->assertTrue($after['entitlement_enabled']);
    }

    public function test_the_underlying_computation_derives_the_most_severe_health_state_across_connections(): void
    {
        $firm = Firm::factory()->activated()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            $a = FirmIntegration::factory()->forFirm($firm)->create();
            $b = FirmIntegration::factory()->forFirm($firm)->create();

            app(HealthStateService::class)->recordSuccess($a->id, $firm->id);
            app(HealthStateService::class)->recordProviderError(
                $b->id,
                $firm->id,
                new SanitizedHealthDiagnostic(
                    SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR,
                    SanitizedHealthDiagnostic::OPERATION_HEALTH_CHECK,
                    503,
                )
            );
        });

        $result = $this->computeForFirm($firm->fresh());

        // One healthy + one degraded (single provider_error failure) ->
        // the worse of the two must win.
        $this->assertSame('degraded', $result['health_summary_state']);
    }

    public function test_the_scheduled_command_only_dispatches_a_job_for_activated_firms(): void
    {
        Queue::fake();

        Firm::factory()->create(['activation_status' => FirmActivationStatus::Draft]);
        Firm::factory()->create(['activation_status' => FirmActivationStatus::Onboarding]);
        $activated = Firm::factory()->activated()->create();

        $this->artisan('integrations:platform-overview:refresh')->assertExitCode(0);

        Queue::assertPushed(RefreshIntegrationPlatformOverviewSummaryJob::class, 1);
        Queue::assertPushed(RefreshIntegrationPlatformOverviewSummaryJob::class, fn (RefreshIntegrationPlatformOverviewSummaryJob $job) => $job->firmId === $activated->id);
    }

    public function test_the_job_gracefully_no_ops_when_the_firm_no_longer_exists(): void
    {
        // handle() looks the firm up and returns BEFORE ever reaching
        // refreshForFirm()/the broken upsert — this path is unaffected
        // by the discovered bug above.
        $job = new RefreshIntegrationPlatformOverviewSummaryJob(999999999);

        $job->handle(app(IntegrationPlatformOverviewSummaryService::class));
        $this->addToAssertionCount(1);

        $this->assertSame(
            0,
            DB::table('integration_platform_overview_summaries')->where('firm_id', 999999999)->count()
        );
    }

    public function test_overview_page_renders_each_firms_row_correctly_without_cross_contamination(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();

        // Seeded directly (bypassing the broken write-path service — see
        // this file's class docblock) purely to exercise the READ side.
        $this->seedSummaryRow($firmA, ['connection_count_active' => 2]);
        $this->seedSummaryRow($firmB, ['connection_count_active' => 1]);

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        $rows = app(IntegrationPlatformOversightReadService::class)->overviewSummaries($admin);

        $rowA = $rows->firstWhere('firm_uuid', $firmA->uuid);
        $rowB = $rows->firstWhere('firm_uuid', $firmB->uuid);

        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        $this->assertSame(2, $rowA['connection_count_active']);
        $this->assertSame(1, $rowB['connection_count_active']);

        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformIntegrationOverviewPage::class);
        $test->assertOk();
        $test->assertSee($firmA->uuid);
        $test->assertSee($firmB->uuid);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function computeForFirm(Firm $firm): array
    {
        $method = new ReflectionMethod(IntegrationPlatformOverviewSummaryService::class, 'computeForFirm');
        $method->setAccessible(true);

        return $this->runWithFirmContext(
            $firm,
            fn () => $method->invoke(app(IntegrationPlatformOverviewSummaryService::class), $firm)
        );
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
