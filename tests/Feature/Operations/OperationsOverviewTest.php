<?php

namespace Tests\Feature\Operations;

use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Enums\IncidentSeverity;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformOperationsOverviewPage;
use App\Models\HealthCheck;
use App\Models\PlatformAdmin;
use App\Services\BackupRestore\FakeBackupRestoreDrillRunner;
use App\Services\BackupRestoreTestService;
use App\Services\IncidentService;
use App\Services\OperationsOverviewService;
use App\Services\PlatformRoleService;
use App\Services\SchedulerHealthService;
use App\Services\StatusPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Operations Control Plane — the Overview page.
 *
 * A summary page is where truthfulness is hardest to keep: every
 * unavailable signal wants to collapse into a zero, and every
 * unmonitored surface wants to disappear. These tests hold the line
 * on both.
 */
class OperationsOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function overview(): OperationsOverviewService
    {
        return app(OperationsOverviewService::class);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function recordHealth(HealthCheckType $type, HealthCheckStatus $status): void
    {
        HealthCheck::create([
            'firm_id' => null,
            'check_type' => $type,
            'status' => $status,
            'checked_at' => now(),
        ]);
    }

    // --- Authorization ---

    public function test_an_operations_admin_can_reach_the_overview(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(PlatformOperationsOverviewPage::getUrl())
            ->assertOk();
    }

    public function test_an_admin_without_an_operations_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')
            ->get(PlatformOperationsOverviewPage::getUrl())
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected(): void
    {
        $this->get(PlatformOperationsOverviewPage::getUrl())->assertRedirect();
    }

    // --- Signals are real or explicitly unavailable ---

    public function test_release_is_reported_as_unavailable_rather_than_fabricated(): void
    {
        $release = $this->overview()->release();

        $this->assertFalse($release['available']);
        $this->assertFalse($release['desired_version_available']);
        $this->assertFalse($release['version_skew_calculable'], 'version skew must be Not Calculable, never 0');
        $this->assertStringContainsString('No authoritative SaaS release source exists', $release['reason']);
    }

    public function test_worker_and_throughput_signals_are_unavailable_not_zero(): void
    {
        $queues = $this->overview()->queues();

        $this->assertFalse($queues['worker_evidence']['available']);
        $this->assertNull($queues['worker_evidence']['expected_workers']);
        $this->assertNull($queues['worker_evidence']['healthy_workers']);
        $this->assertFalse($queues['processed_recently_evidence']['available']);
        $this->assertNull($queues['processed_recently_evidence']['processed_recently']);
    }

    public function test_a_measured_zero_is_distinct_from_an_unavailable_signal(): void
    {
        $queues = $this->overview()->queues();

        // Nothing is queued: that is a real, measured zero.
        $this->assertSame(0, $queues['total_pending']);
        $this->assertSame(0, $queues['total_failed']);

        // Nothing is known about workers: that is an absence.
        $this->assertNull($queues['worker_evidence']['healthy_workers']);
    }

    public function test_scheduler_execution_history_is_reported_as_unavailable(): void
    {
        $scheduler = $this->overview()->scheduler();

        $this->assertFalse($scheduler['execution_history_available']);
        $this->assertGreaterThan(0, $scheduler['registered_count']);
    }

    public function test_data_protection_never_reports_a_measured_rpo_from_a_simulated_drill(): void
    {
        app(BackupRestoreTestService::class)
            ->runDrill(new FakeBackupRestoreDrillRunner);

        $data = $this->overview()->dataProtection();

        $this->assertFalse($data['verified_restore']);
        $this->assertSame('Not Yet Measured', $data['actual_rpo_label']);
        $this->assertSame('Not Yet Measured', $data['actual_rto_label']);
        $this->assertSame('simulated', $data['recorded_figure_qualifier']);
    }

    public function test_fleet_is_reported_as_simulation_only_and_not_production_safe(): void
    {
        $fleet = $this->overview()->fleet();

        $this->assertTrue($fleet['simulation_only']);
        $this->assertFalse($fleet['production_safe']);
        $this->assertGreaterThan(0, $fleet['missing_controls']);
        $this->assertFalse($fleet['canary_available']);
    }

    public function test_status_communications_report_internal_records_as_internal(): void
    {
        app(StatusPageService::class)->publish('investigating', 'API', 'Investigating.', now());

        $status = $this->overview()->statusCommunications();

        $this->assertFalse($status['is_publicly_published']);
        $this->assertSame('Recorded internally — not published publicly', $status['publication_semantics']);
        $this->assertSame(1, $status['published_records']);
    }

    public function test_incident_counts_come_from_the_latest_event_per_incident(): void
    {
        $service = app(IncidentService::class);

        $critical = $service->open(null, IncidentSeverity::Critical, 'API down', customerImpact: true, notificationNeeded: true);
        $resolved = $service->open(null, IncidentSeverity::High, 'Slow queries');
        $service->resolve(null, $resolved->correlation_id, 'Tuned the query.');

        $incidents = $this->overview()->incidents();

        $this->assertSame(1, $incidents['active'], 'a resolved incident is not active');
        $this->assertSame(1, $incidents['critical_active']);
        $this->assertSame(1, $incidents['unresolved_with_customer_impact']);
        $this->assertSame(1, $incidents['awaiting_customer_notification']);
        $this->assertSame($critical->correlation_id, $critical->correlation_id);
    }

    // --- Requires Attention ---

    public function test_requires_attention_is_empty_when_nothing_observable_is_wrong(): void
    {
        app(SchedulerHealthService::class)->recordHeartbeat();

        foreach ([HealthCheckType::FailedJobs, HealthCheckType::QueueWorkers, HealthCheckType::Scheduler, HealthCheckType::TenantIsolationAnomalies] as $type) {
            $this->recordHealth($type, HealthCheckStatus::Healthy);
        }
        foreach ([HealthCheckType::WebUptime, HealthCheckType::Storage, HealthCheckType::EmailDelivery, HealthCheckType::PaymentWebhooks, HealthCheckType::DocumentScanning] as $type) {
            $this->recordHealth($type, HealthCheckStatus::NotMonitored);
        }

        $this->assertSame([], $this->overview()->requiresAttention());
    }

    public function test_a_known_monitoring_gap_never_appears_as_a_live_alert(): void
    {
        app(SchedulerHealthService::class)->recordHeartbeat();

        foreach (HealthCheckType::cases() as $type) {
            $this->recordHealth(
                $type,
                in_array($type, [HealthCheckType::WebUptime, HealthCheckType::Storage, HealthCheckType::EmailDelivery, HealthCheckType::PaymentWebhooks, HealthCheckType::DocumentScanning], true)
                    ? HealthCheckStatus::NotMonitored
                    : HealthCheckStatus::Healthy,
            );
        }

        $attention = $this->overview()->requiresAttention();
        $gaps = $this->overview()->coverageGaps();

        $this->assertSame([], $attention, 'unmonitored surfaces are gaps, not alerts');
        $this->assertNotEmpty($gaps, 'but they must still be reported somewhere');
        $this->assertTrue(
            collect($gaps)->contains(fn (array $gap): bool => str_contains($gap['gap'], 'Web Uptime')),
        );
    }

    public function test_a_critical_health_check_raises_attention_with_its_reason(): void
    {
        app(SchedulerHealthService::class)->recordHeartbeat();
        $this->recordHealth(HealthCheckType::Scheduler, HealthCheckStatus::Unhealthy);

        $attention = collect($this->overview()->requiresAttention());

        $item = $attention->first(
            fn (array $i): bool => $i['area'] === 'Service Health' && $i['condition'] === 'Scheduler',
        );

        $this->assertNotNull($item, 'the failing check must appear by name in the attention queue');
        $this->assertSame('critical', $item['severity']);
        $this->assertSame('Check reported a critical failure.', $item['detail']);

        // Checks that have simply never run are also surfaced, but as
        // unknown-state warnings rather than observed failures — the
        // two must not be conflated.
        $neverObserved = $attention->first(
            fn (array $i): bool => $i['area'] === 'Service Health' && $i['condition'] === 'Failed Jobs',
        );

        $this->assertNotNull($neverObserved);
        $this->assertSame('warning', $neverObserved['severity']);
        $this->assertStringContainsString('never recorded an observation', $neverObserved['detail']);
    }

    public function test_a_missing_scheduler_heartbeat_raises_a_critical_item(): void
    {
        $attention = collect($this->overview()->requiresAttention());

        $this->assertTrue($attention->contains(
            fn (array $item): bool => $item['area'] === 'Scheduler' && $item['condition'] === 'Scheduler heartbeat never observed'
        ));
    }

    public function test_failed_jobs_raise_a_queue_attention_item(): void
    {
        app(SchedulerHealthService::class)->recordHeartbeat();

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ExampleJob']),
            'exception' => 'RuntimeException: broke',
            'failed_at' => now(),
        ]);

        $attention = collect($this->overview()->requiresAttention());

        $this->assertTrue($attention->contains(fn (array $item): bool => $item['area'] === 'Queues'));
    }

    public function test_a_critical_incident_raises_attention(): void
    {
        app(SchedulerHealthService::class)->recordHeartbeat();
        app(IncidentService::class)->open(null, IncidentSeverity::Critical, 'API down');

        $attention = collect($this->overview()->requiresAttention());

        $this->assertTrue($attention->contains(
            fn (array $item): bool => $item['area'] === 'Incidents' && $item['severity'] === 'critical'
        ));
    }

    // --- Recent changes ---

    public function test_the_recent_change_feed_reports_real_records(): void
    {
        app(IncidentService::class)->open(null, IncidentSeverity::High, 'Elevated errors');
        app(StatusPageService::class)->publish('investigating', 'API', 'Investigating.', now());

        $changes = collect($this->overview()->recentChanges());

        $this->assertTrue($changes->contains(fn (array $c): bool => $c['area'] === 'Incident'));
        $this->assertTrue($changes->contains(fn (array $c): bool => $c['area'] === 'Status Record'));
    }

    public function test_the_change_feed_reports_health_transitions_not_every_observation(): void
    {
        // Three identical observations then one change: only the
        // change is a change.
        foreach ([30, 20, 10] as $minutesAgo) {
            HealthCheck::create([
                'firm_id' => null,
                'check_type' => HealthCheckType::FailedJobs,
                'status' => HealthCheckStatus::Healthy,
                'checked_at' => now()->subMinutes($minutesAgo),
            ]);
        }
        HealthCheck::create([
            'firm_id' => null,
            'check_type' => HealthCheckType::FailedJobs,
            'status' => HealthCheckStatus::Degraded,
            'checked_at' => now(),
        ]);

        $healthChanges = collect($this->overview()->recentChanges())
            ->filter(fn (array $c): bool => $c['area'] === 'Health');

        $this->assertCount(1, $healthChanges, 'four observations, one transition');
        $this->assertStringContainsString('changed from Healthy to Degraded', $healthChanges->first()['summary']);
    }

    public function test_the_change_feed_is_bounded(): void
    {
        for ($i = 0; $i < 40; $i++) {
            app(IncidentService::class)->open(null, IncidentSeverity::Low, "Incident {$i}");
        }

        $this->assertLessThanOrEqual(
            OperationsOverviewService::RECENT_CHANGE_LIMIT,
            count($this->overview()->recentChanges()),
        );
    }

    // --- Page rendering ---

    public function test_the_page_never_claims_healthy_when_nothing_is_monitored(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformOperationsOverviewPage::getUrl());

        $response->assertOk();
        $response->assertSee('Overall: Unknown');
    }

    public function test_the_page_renders_every_domain_section_with_its_gaps(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformOperationsOverviewPage::getUrl());

        $response->assertOk();
        $response->assertSee('Platform Health');
        $response->assertSee('Incidents');
        $response->assertSee('Queues &amp; Workers', false);
        $response->assertSee('Scheduler');
        $response->assertSee('Data Protection');
        $response->assertSee('Release');
        $response->assertSee('Fleet Migrations');
        $response->assertSee('Status Communications');
        $response->assertSee('Coverage Gaps');
    }

    public function test_the_page_states_the_unavailable_signals_explicitly(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformOperationsOverviewPage::getUrl());

        $response->assertOk();
        $response->assertSee('Current SaaS release: Unknown');
        $response->assertSee('Version skew: Not Calculable');
        $response->assertSee('Workers expected: Not Monitored');
        $response->assertSee('Execution History Not Available');
        $response->assertSee('Actual RPO: Not Yet Measured');
        $response->assertSee('Verified real restore: Never');
        $response->assertSee('Canary results: Not Available');
    }

    public function test_the_empty_attention_queue_states_what_it_does_not_cover(): void
    {
        app(SchedulerHealthService::class)->recordHeartbeat();

        foreach (HealthCheckType::cases() as $type) {
            $this->recordHealth(
                $type,
                in_array($type, [HealthCheckType::WebUptime, HealthCheckType::Storage, HealthCheckType::EmailDelivery, HealthCheckType::PaymentWebhooks, HealthCheckType::DocumentScanning], true)
                    ? HealthCheckStatus::NotMonitored
                    : HealthCheckStatus::Healthy,
            );
        }

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformOperationsOverviewPage::getUrl());

        $response->assertOk();
        $response->assertSee('Requires Attention — None');
        $response->assertSee('is not the same as');
    }
}
