<?php

namespace Tests\Feature\Operations;

use App\Enums\HealthCheckMonitoringType;
use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Enums\OperationsFreshness;
use App\Models\HealthCheck;
use App\Services\HealthCheckRegistry;
use App\Services\HealthCheckService;
use App\Services\OperationsHealthEvaluationService;
use App\Services\QueueHealthService;
use App\Services\SchedulerHealthService;
use App\Services\TenantIsolationAnomalyService;
use App\ValueObjects\HealthCheckResult;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Operations Control Plane — Phase 1 (truthfulness) regression.
 *
 * The single most important property proven here: a health check
 * with no probe behind it never, under any circumstance, renders as
 * Healthy. Everything else in the Operations console is downstream of
 * operators being able to trust that green means green.
 */
class ServiceHealthTruthTest extends TestCase
{
    use RefreshDatabase;

    private HealthCheckRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new HealthCheckRegistry(
            new QueueHealthService,
            new SchedulerHealthService,
            new TenantIsolationAnomalyService,
        );
    }

    private function evaluator(): OperationsHealthEvaluationService
    {
        return new OperationsHealthEvaluationService($this->registry);
    }

    /**
     * The surfaces with no probe behind them at all. Named explicitly
     * rather than derived, so that making one of them genuinely
     * monitored has to be a deliberate edit to this list — it can
     * never happen by accident.
     *
     * Storage and DocumentScanning gained real LiveProbes, and
     * EmailDelivery a real ConfigurationCheck, in commit a998600f — see
     * tests/Feature/HealthCheck/HealthCheckRegistryTest.php for their
     * dedicated truthfulness coverage. Only WebUptime (no external
     * uptime provider) and PaymentWebhooks (explicitly out of scope)
     * remain genuine stubs.
     *
     * @return array<int, array{0: HealthCheckType}>
     */
    public static function unmonitoredCheckTypes(): array
    {
        return [
            'web uptime' => [HealthCheckType::WebUptime],
            'payment webhooks' => [HealthCheckType::PaymentWebhooks],
        ];
    }

    #[DataProvider('unmonitoredCheckTypes')]
    public function test_a_stub_check_reports_not_monitored_and_never_healthy(HealthCheckType $type): void
    {
        $result = $this->registry->run($type);

        $this->assertSame(
            HealthCheckStatus::NotMonitored,
            $result->status,
            "{$type->value} has no probe behind it and must report NotMonitored",
        );
        $this->assertNotSame(HealthCheckStatus::Healthy, $result->status);
        $this->assertSame(HealthCheckMonitoringType::NotMonitored, $result->monitoringType);
    }

    public function test_no_registered_check_without_real_evidence_can_ever_report_healthy(): void
    {
        foreach ($this->registry->runAll() as $result) {
            if ($result->monitoringType->isRealEvidence()) {
                continue;
            }

            $this->assertNotSame(
                HealthCheckStatus::Healthy,
                $result->status,
                "{$result->checkType->value} reports Healthy without real evidence behind it",
            );
        }
    }

    public function test_registry_counts_are_derived_not_hardcoded(): void
    {
        $counts = $this->registry->monitoringTypeCounts();

        $this->assertSame(
            count(HealthCheckType::cases()),
            array_sum($counts),
            'every check type must be accounted for in exactly one monitoring-type bucket',
        );

        // The real, current shape of the registry: four internally
        // measured checks, two genuinely unmonitored (WebUptime,
        // PaymentWebhooks), two live probes (Storage, DocumentScanning —
        // see commit a998600f), one configuration check (EmailDelivery).
        $this->assertSame(4, $counts[HealthCheckMonitoringType::InternalMetric->value]);
        $this->assertSame(2, $counts[HealthCheckMonitoringType::NotMonitored->value]);
        $this->assertSame(2, $counts[HealthCheckMonitoringType::LiveProbe->value]);
        $this->assertSame(1, $counts[HealthCheckMonitoringType::ConfigurationCheck->value]);
    }

    public function test_counts_follow_the_registry_when_a_check_is_made_real(): void
    {
        // WebUptime, not Storage — Storage already carries a real
        // LiveProbe (commit a998600f), so re-registering it as LiveProbe
        // wouldn't move it out of NotMonitored and this delta assertion
        // would no longer prove anything. WebUptime remains a genuine
        // stub (see unmonitoredCheckTypes() above).
        $before = $this->registry->monitoringTypeCounts();

        $this->registry->register(
            HealthCheckType::WebUptime,
            fn () => new HealthCheckResult(HealthCheckType::WebUptime, HealthCheckStatus::Healthy, 'probed'),
            HealthCheckMonitoringType::LiveProbe,
        );

        $after = $this->registry->monitoringTypeCounts();

        $this->assertSame($before[HealthCheckMonitoringType::LiveProbe->value] + 1, $after[HealthCheckMonitoringType::LiveProbe->value]);
        $this->assertSame($before[HealthCheckMonitoringType::NotMonitored->value] - 1, $after[HealthCheckMonitoringType::NotMonitored->value]);
    }

    public function test_registry_stamps_monitoring_type_and_a_callable_cannot_overclaim_it(): void
    {
        // A callable that lies about its own provenance.
        $this->registry->register(
            HealthCheckType::WebUptime,
            fn () => new HealthCheckResult(
                HealthCheckType::WebUptime,
                HealthCheckStatus::Healthy,
                'claims to be a live probe',
                HealthCheckMonitoringType::LiveProbe,
            ),
            HealthCheckMonitoringType::NotMonitored,
        );

        $result = $this->registry->run(HealthCheckType::WebUptime);

        $this->assertSame(
            HealthCheckMonitoringType::NotMonitored,
            $result->monitoringType,
            'the registry, not the callable, decides what counts as monitored',
        );
    }

    public function test_a_stale_healthy_observation_is_surfaced_as_unknown_not_healthy(): void
    {
        $threshold = $this->evaluator()->freshnessThresholdSeconds();

        HealthCheck::create([
            'firm_id' => null,
            'check_type' => HealthCheckType::FailedJobs,
            'status' => HealthCheckStatus::Healthy,
            'detail' => '0 failed job(s)',
            'checked_at' => now()->subSeconds($threshold + 60),
        ]);

        $state = $this->evaluator()->currentStateFor(HealthCheckType::FailedJobs);

        $this->assertSame(HealthCheckStatus::Healthy, $state->status, 'the recorded row itself is unchanged');
        $this->assertSame(OperationsFreshness::Stale, $state->freshness);
        $this->assertSame(
            HealthCheckStatus::Unknown,
            $state->effectiveStatus(),
            'a stale pass must not be presented as current health',
        );
        $this->assertTrue($state->requiresAttention());
    }

    public function test_freshness_boundary_is_exact(): void
    {
        $threshold = $this->evaluator()->freshnessThresholdSeconds();

        HealthCheck::create([
            'firm_id' => null,
            'check_type' => HealthCheckType::FailedJobs,
            'status' => HealthCheckStatus::Healthy,
            'checked_at' => now()->subSeconds($threshold),
        ]);

        $this->assertSame(
            OperationsFreshness::Fresh,
            $this->evaluator()->currentStateFor(HealthCheckType::FailedJobs)->freshness,
            'exactly at the threshold is still fresh',
        );

        HealthCheck::query()->delete();

        HealthCheck::create([
            'firm_id' => null,
            'check_type' => HealthCheckType::FailedJobs,
            'status' => HealthCheckStatus::Healthy,
            'checked_at' => now()->subSeconds($threshold + 1),
        ]);

        $this->assertSame(
            OperationsFreshness::Stale,
            $this->evaluator()->currentStateFor(HealthCheckType::FailedJobs)->freshness,
            'one second past the threshold is stale',
        );
    }

    public function test_a_check_with_no_history_reports_never_observed_with_null_counters(): void
    {
        $state = $this->evaluator()->currentStateFor(HealthCheckType::FailedJobs);

        $this->assertSame(OperationsFreshness::NeverObserved, $state->freshness);
        $this->assertNull($state->lastCheckedAt);
        $this->assertNull(
            $state->consecutiveFailures,
            'no history must read as "no data", never as "0 failures"',
        );
        $this->assertNull($state->lastSuccessAt);
        $this->assertNull($state->lastFailureAt);
        $this->assertSame(HealthCheckStatus::Unknown, $state->effectiveStatus());
    }

    public function test_consecutive_failures_and_last_change_are_measured_from_real_history(): void
    {
        $sequence = [
            [HealthCheckStatus::Healthy, 50],
            [HealthCheckStatus::Healthy, 40],
            [HealthCheckStatus::Unhealthy, 30],
            [HealthCheckStatus::Unhealthy, 20],
            [HealthCheckStatus::Unhealthy, 10],
        ];

        foreach ($sequence as [$status, $minutesAgo]) {
            HealthCheck::create([
                'firm_id' => null,
                'check_type' => HealthCheckType::Scheduler,
                'status' => $status,
                'checked_at' => now()->subMinutes($minutesAgo),
            ]);
        }

        $state = $this->evaluator()->currentStateFor(HealthCheckType::Scheduler);

        $this->assertSame(3, $state->consecutiveFailures);
        $this->assertNotNull($state->lastSuccessAt);
        $this->assertSame(40, (int) round(now()->diffInMinutes($state->lastSuccessAt, absolute: true)));
        $this->assertNotNull($state->lastChangedAt);
        $this->assertSame(
            30,
            (int) round(now()->diffInMinutes($state->lastChangedAt, absolute: true)),
            'the status last changed when the first of the current failure run was recorded',
        );
    }

    public function test_last_changed_is_null_rather_than_guessed_when_the_window_never_changes(): void
    {
        foreach ([30, 20, 10] as $minutesAgo) {
            HealthCheck::create([
                'firm_id' => null,
                'check_type' => HealthCheckType::Scheduler,
                'status' => HealthCheckStatus::Healthy,
                'checked_at' => now()->subMinutes($minutesAgo),
            ]);
        }

        $this->assertNull(
            $this->evaluator()->currentStateFor(HealthCheckType::Scheduler)->lastChangedAt,
            'the real change point is older than the inspected window, so it must not be invented',
        );
    }

    public function test_summary_never_reports_healthy_when_every_signal_is_unmonitored(): void
    {
        foreach (HealthCheckType::cases() as $type) {
            HealthCheck::create([
                'firm_id' => null,
                'check_type' => $type,
                'status' => HealthCheckStatus::NotMonitored,
                'checked_at' => now(),
                'metadata_json' => ['monitoring_type' => HealthCheckMonitoringType::NotMonitored->value],
            ]);
        }

        $summary = $this->evaluator()->summary();

        $this->assertNotSame(HealthCheckStatus::Healthy, $summary['overall']);
        $this->assertSame(0, $summary['healthy']);
    }

    public function test_summary_counts_unmonitored_separately_from_healthy(): void
    {
        HealthCheck::create([
            'firm_id' => null,
            'check_type' => HealthCheckType::FailedJobs,
            'status' => HealthCheckStatus::Healthy,
            'checked_at' => now(),
        ]);

        foreach ([HealthCheckType::WebUptime, HealthCheckType::Storage] as $type) {
            HealthCheck::create([
                'firm_id' => null,
                'check_type' => $type,
                'status' => HealthCheckStatus::NotMonitored,
                'checked_at' => now(),
            ]);
        }

        $summary = $this->evaluator()->summary();

        $this->assertSame(1, $summary['healthy']);
        $this->assertGreaterThanOrEqual(2, $summary['not_monitored']);
        $this->assertSame(count(HealthCheckType::cases()), $summary['total']);
    }

    public function test_a_critical_observation_dominates_the_overall_verdict(): void
    {
        HealthCheck::create([
            'firm_id' => null,
            'check_type' => HealthCheckType::FailedJobs,
            'status' => HealthCheckStatus::Healthy,
            'checked_at' => now(),
        ]);
        HealthCheck::create([
            'firm_id' => null,
            'check_type' => HealthCheckType::Scheduler,
            'status' => HealthCheckStatus::Unhealthy,
            'checked_at' => now(),
        ]);

        $this->assertSame(HealthCheckStatus::Unhealthy, $this->evaluator()->summary()['overall']);
    }

    public function test_an_unmonitored_check_is_a_reported_gap_not_a_live_alert(): void
    {
        $evaluator = $this->evaluator();

        $gapTypes = array_map(
            fn ($state): string => $state->checkType->value,
            $evaluator->monitoringGaps(),
        );

        $this->assertContains(HealthCheckType::WebUptime->value, $gapTypes);

        foreach ($evaluator->requiringAttention() as $state) {
            $this->assertNotSame(
                HealthCheckMonitoringType::NotMonitored,
                $state->monitoringType,
                'a known monitoring gap must not masquerade as a live incident',
            );
        }
    }

    public function test_persisted_observations_record_their_monitoring_type(): void
    {
        app(HealthCheckService::class)->runAllAndRecord(null);

        $row = HealthCheck::query()
            ->whereNull('firm_id')
            ->where('check_type', HealthCheckType::WebUptime->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(HealthCheckStatus::NotMonitored, $row->status);
        $this->assertSame(
            HealthCheckMonitoringType::NotMonitored->value,
            $row->metadata_json['monitoring_type'] ?? null,
        );
    }

    /**
     * Guards the one hardcoded number in the freshness model against
     * drift: the expected cadence constant must keep matching the
     * cadence actually registered in bootstrap/app.php.
     */
    public function test_expected_cadence_matches_the_registered_schedule(): void
    {
        Artisan::call('about');

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'health:checks:run'));

        $this->assertNotNull($event, 'health:checks:run must be registered on the schedule');
        $this->assertSame(
            '*/5 * * * *',
            $event->expression,
            'the registered cadence changed — update OperationsHealthEvaluationService::EXPECTED_CADENCE_SECONDS to match',
        );
        $this->assertSame(300, OperationsHealthEvaluationService::EXPECTED_CADENCE_SECONDS);
    }
}
