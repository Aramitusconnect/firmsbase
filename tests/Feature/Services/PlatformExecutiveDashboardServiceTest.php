<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\FirmActivationStatus;
use App\Enums\PlatformRoleCode;
use App\Integrations\Enums\HealthSummaryState;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Services\PlatformExecutiveDashboardService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PlatformExecutiveDashboardServiceTest — Phase 1 FirmsVault Admin
 * Control Center, final scope item. Proves each Executive Dashboard
 * metric's real source against known fixtures: firms-by-status (the
 * real 3 FirmActivationStatus cases only), the cross-firm firm-user
 * total (via PlatformFirmUserDirectoryService::countAll()'s per-firm
 * loop), platform-admin/MFA counts, integration summary aggregation
 * (over the existing `integration_platform_overview_summaries` table,
 * never a live query), queue/failed-job counts, and per-section
 * authorization gating — plus empty-state rendering and a no-N+1 proof
 * on the one genuinely cross-firm read path (total_firm_users).
 *
 * Cache::flush() is called before every cache-dependent assertion,
 * matching RlsSecurityReportServiceTest's own established discipline —
 * CACHE_STORE=array in phpunit.xml persists for the whole test-process
 * lifetime, not per-test, so a prior test's cached report/security-events
 * read would otherwise leak into this file's assertions.
 */
final class PlatformExecutiveDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PlatformExecutiveDashboardService
    {
        return app(PlatformExecutiveDashboardService::class);
    }

    private function superAdmin(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        return $admin;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ------------------------------------------------------------
    // Empty state
    // ------------------------------------------------------------

    public function test_snapshot_reports_honest_zero_state_when_no_data_exists(): void
    {
        $admin = $this->superAdmin();

        $snapshot = $this->service()->snapshot($admin);

        $this->assertTrue($snapshot['firms']['authorized']);
        $this->assertSame(0, $snapshot['firms']['total']);
        $this->assertSame(['draft' => 0, 'onboarding' => 0, 'activated' => 0], $snapshot['firms']['by_status']);
        $this->assertSame(0, $snapshot['firms']['total_firm_users']);

        $this->assertTrue($snapshot['integrations']['authorized']);
        $this->assertSame(0, $snapshot['integrations']['firms_with_summary']);
        $this->assertSame(0, $snapshot['integrations']['connected_count']);
        $this->assertSame(0, $snapshot['integrations']['attention_needed_firm_count']);
        $this->assertNull($snapshot['integrations']['latest_computed_at']);

        $this->assertTrue($snapshot['recent_activity']['authorized']);
        $this->assertCount(0, $snapshot['recent_activity']['events']);

        // Exactly one PlatformAdmin exists (the acting admin) — never 0,
        // this is not an "empty data" case for this section.
        $this->assertSame(1, $snapshot['platform_admins']['active_count']);
    }

    // ------------------------------------------------------------
    // Firms section — real 3 FirmActivationStatus cases only
    // ------------------------------------------------------------

    public function test_snapshot_firms_section_counts_by_the_real_three_activation_statuses(): void
    {
        $admin = $this->superAdmin();

        Firm::factory()->count(2)->create(['activation_status' => FirmActivationStatus::Draft]);
        Firm::factory()->create(['activation_status' => FirmActivationStatus::Onboarding]);
        Firm::factory()->count(3)->create(['activation_status' => FirmActivationStatus::Activated]);

        $snapshot = $this->service()->snapshot($admin);

        $this->assertSame(6, $snapshot['firms']['total']);
        $this->assertSame([
            'draft' => 2,
            'onboarding' => 1,
            'activated' => 3,
        ], $snapshot['firms']['by_status']);
        // Confirms no fourth/"suspended"-style key was fabricated.
        $this->assertCount(3, $snapshot['firms']['by_status']);
    }

    public function test_snapshot_total_firm_users_sums_across_every_firm(): void
    {
        $admin = $this->superAdmin();

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->createWithFirmContext($firmA, fn () => FirmUser::factory()->forFirm($firmA)->create());
        $this->createWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->create());
        $this->createWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->create());

        $snapshot = $this->service()->snapshot($admin);

        $this->assertSame(3, $snapshot['firms']['total_firm_users']);
    }

    /**
     * No-N+1 proof for the one genuinely cross-firm read in this
     * service: total_firm_users must cost exactly one firm_users query
     * PER FIRM (PlatformFirmUserDirectoryService::countAll()'s
     * documented O(firm count) trade-off), never one query per
     * firm_user row.
     */
    public function test_total_firm_users_executes_exactly_one_query_per_firm_not_per_row(): void
    {
        $admin = $this->superAdmin();

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $firmC = Firm::factory()->create();

        foreach ([$firmA, $firmB, $firmC] as $firm) {
            $this->createWithFirmContext($firm, fn () => FirmUser::factory()->count(4)->forFirm($firm)->create());
        }

        $captured = [];
        DB::listen(function ($query) use (&$captured): void {
            $captured[] = $query->sql;
        });

        $snapshot = $this->service()->snapshot($admin);

        $this->assertSame(12, $snapshot['firms']['total_firm_users']);

        $firmUserQueries = array_values(array_filter(
            $captured,
            fn (string $sql): bool => stripos($sql, 'firm_users') !== false && stripos($sql, 'select') !== false
        ));

        $this->assertCount(
            3,
            $firmUserQueries,
            'Expected exactly one firm_users SELECT per firm (3 firms), never one per firm_user row (12 rows).'
        );
    }

    // ------------------------------------------------------------
    // Platform admins section
    // ------------------------------------------------------------

    public function test_snapshot_platform_admins_section_counts_active_and_unconfirmed_mfa(): void
    {
        $admin = $this->superAdmin();

        PlatformAdmin::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => null, 'two_factor_secret' => null]);
        PlatformAdmin::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => null, 'two_factor_secret' => null]);
        PlatformAdmin::factory()->create(['is_active' => false, 'two_factor_confirmed_at' => now()]);

        $snapshot = $this->service()->snapshot($admin);

        // 3 fixtures + the acting SuperAdmin = 4 total, minus the one
        // inactive fixture = 3 active.
        $this->assertSame(3, $snapshot['platform_admins']['active_count']);
        $this->assertSame(2, $snapshot['platform_admins']['without_confirmed_mfa_count']);
    }

    // ------------------------------------------------------------
    // Integrations section — aggregates the existing summary table
    // ------------------------------------------------------------

    public function test_snapshot_integrations_section_aggregates_the_existing_summary_table(): void
    {
        $admin = $this->superAdmin();

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->insertOverviewSummary($firmA, [
            'connection_count_active' => 3,
            'health_summary_state' => HealthSummaryState::Healthy->value,
            'failed_permanent_sync_item_count' => 1,
            'dead_lettered_outbox_event_count' => 0,
            'open_conflict_count' => 0,
        ]);

        $this->insertOverviewSummary($firmB, [
            'connection_count_active' => 2,
            'health_summary_state' => HealthSummaryState::Degraded->value,
            'failed_permanent_sync_item_count' => 4,
            'dead_lettered_outbox_event_count' => 2,
            'open_conflict_count' => 5,
        ]);

        $snapshot = $this->service()->snapshot($admin);
        $integrations = $snapshot['integrations'];

        $this->assertSame(2, $integrations['firms_with_summary']);
        $this->assertSame(5, $integrations['connected_count']);
        $this->assertSame(1, $integrations['attention_needed_firm_count'], 'Only the Degraded firm should count as needing attention.');
        $this->assertSame(5, $integrations['failed_permanent_sync_item_count']);
        $this->assertSame(2, $integrations['dead_lettered_outbox_event_count']);
        $this->assertSame(5, $integrations['open_conflict_count']);
        $this->assertNotNull($integrations['latest_computed_at']);
    }

    // ------------------------------------------------------------
    // System section — queue/failed-job observability
    // ------------------------------------------------------------

    public function test_system_section_reports_queue_not_observable_for_a_non_database_driver(): void
    {
        // phpunit.xml sets QUEUE_CONNECTION=sync for the whole test
        // suite — this IS the real, currently-configured non-database
        // driver case, not an artificial one.
        $admin = $this->superAdmin();

        $snapshot = $this->service()->snapshot($admin);

        $this->assertSame('sync', $snapshot['system']['queue_connection']);
        $this->assertFalse($snapshot['system']['queue_pending_jobs_observable']);
        $this->assertNull($snapshot['system']['queue_pending_jobs']);
    }

    public function test_system_section_reports_failed_jobs_count_regardless_of_queue_driver(): void
    {
        $admin = $this->superAdmin();

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $snapshot = $this->service()->snapshot($admin);

        $this->assertSame(1, $snapshot['system']['failed_jobs_count']);
    }

    public function test_system_section_reports_pending_jobs_when_the_database_driver_is_active(): void
    {
        config(['queue.default' => 'database']);
        $admin = $this->superAdmin();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $snapshot = $this->service()->snapshot($admin);

        $this->assertTrue($snapshot['system']['queue_pending_jobs_observable']);
        $this->assertSame(1, $snapshot['system']['queue_pending_jobs']);
    }

    public function test_system_section_honestly_labels_scheduler_status_unavailable(): void
    {
        $admin = $this->superAdmin();

        $snapshot = $this->service()->snapshot($admin);

        $this->assertSame('unavailable', $snapshot['system']['scheduler_status']);
        $this->assertNotEmpty($snapshot['system']['scheduler_status_reason']);
    }

    // ------------------------------------------------------------
    // Security section — reuses RlsSecurityReportService's own cache
    // ------------------------------------------------------------

    public function test_security_section_reuses_the_tenant_isolation_cache_not_a_second_generation(): void
    {
        $admin = $this->superAdmin();

        $first = $this->service()->snapshot($admin);
        $second = $this->service()->snapshot($admin);

        $this->assertSame(
            $first['security']['latest_verification_at'],
            $second['security']['latest_verification_at'],
            'A second snapshot() call within the 5-minute cache window must reuse the same cached report, not regenerate it.'
        );
        $this->assertSame($first['system']['git_commit'], $second['system']['git_commit']);
    }

    // ------------------------------------------------------------
    // Authorization — unauthorized sections carry no real data
    // ------------------------------------------------------------

    public function test_a_sales_rep_sees_every_gated_section_marked_unauthorized_with_no_real_data(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SalesRep);

        Firm::factory()->count(5)->create();

        $snapshot = $this->service()->snapshot($admin);

        $this->assertFalse($snapshot['firms']['authorized']);
        $this->assertArrayNotHasKey('total', $snapshot['firms']);
        $this->assertArrayNotHasKey('by_status', $snapshot['firms']);

        $this->assertFalse($snapshot['platform_admins']['authorized']);
        $this->assertArrayNotHasKey('active_count', $snapshot['platform_admins']);

        $this->assertFalse($snapshot['integrations']['authorized']);
        $this->assertArrayNotHasKey('connected_count', $snapshot['integrations']);

        $this->assertFalse($snapshot['security']['authorized']);
        $this->assertArrayNotHasKey('tenant_isolation', $snapshot['security']);

        $this->assertFalse($snapshot['recent_activity']['authorized']);
        $this->assertCount(0, $snapshot['recent_activity']['events']);

        // Ungated sections remain populated for every admin regardless
        // of role.
        $this->assertArrayHasKey('name', $snapshot['environment']);
        $this->assertArrayHasKey('queue_connection', $snapshot['system']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertOverviewSummary(Firm $firm, array $overrides = []): void
    {
        $now = now();

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
            'computed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }
}
