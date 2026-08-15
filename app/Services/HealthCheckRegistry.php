<?php

namespace App\Services;

use App\Enums\HealthCheckMonitoringType;
use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\ValueObjects\HealthCheckResult;

/**
 * HealthCheckRegistry — pluggable, mirrors Phase 4's
 * ReadinessScorecardRegistry exactly, one layer up (monitoring checks
 * vs. matter readiness components). A check registers a callable
 * keyed by HealthCheckType; runAll() executes every registered check.
 * Unlike ReadinessScorecardRegistry, there is no separate database
 * catalog table gating which checks are "active" — HealthCheckType
 * itself is the closed, reviewed list (see that enum's doc comment),
 * so registration alone determines what runs.
 *
 * Reuses Phase 4's QueueHealthService (QueueWorkers, FailedJobs) and
 * SchedulerHealthService (Scheduler) as two of its default registered
 * sources — no duplicated queue/scheduler-inspection logic (project
 * rule: extend Phase 4's health/queue/scheduler services only
 * additively, and here "additively" means "consumed by," never
 * modified).
 *
 * OPERATIONS CONTROL PLANE CORRECTION (P0 false-confidence fix).
 * Previously, five registered check types (WebUptime, Storage,
 * EmailDelivery, PaymentWebhooks, DocumentScanning) were hardcoded
 * stub callables that returned HealthCheckStatus::Healthy with a
 * detail string explaining they were stubs. The detail string is not
 * what an operator reads at a glance — the badge is — so the Service
 * Health console rendered five permanent green lights for five
 * surfaces with no probe of any kind behind them. Those five now
 * return HealthCheckStatus::NotMonitored, which renders grey and is
 * excluded from every aggregate "healthy" claim. A surface nobody
 * monitors must never be indistinguishable from a surface that was
 * checked and found well.
 *
 * Every registration now also declares a HealthCheckMonitoringType,
 * and the registry — not the individual closure — stamps it onto the
 * result. That makes "how many checks are actually real" a derived,
 * dynamic fact (see monitoringTypeCounts()) rather than a hand-
 * maintained sentence in a Blade file that silently goes stale.
 *
 * No real external monitoring provider is introduced here (project
 * rule, and an explicit stop gate for this mission): making WebUptime/
 * Storage/EmailDelivery/PaymentWebhooks/DocumentScanning genuinely
 * monitored requires a new external dependency and owner approval.
 * Until then they are reported honestly as unmonitored.
 */
class HealthCheckRegistry
{
    /**
     * @var array<string, callable(): HealthCheckResult>
     */
    private array $checks = [];

    /**
     * The declared provenance of each registered check, keyed exactly
     * like $checks. The registry is authoritative for this — a
     * callable cannot claim monitoring it does not have.
     *
     * @var array<string, HealthCheckMonitoringType>
     */
    private array $monitoringTypes = [];

    public function __construct(
        private QueueHealthService $queueHealth,
        private SchedulerHealthService $schedulerHealth,
        private TenantIsolationAnomalyService $tenantIsolationAnomaly,
    ) {
        $this->registerDefaults();
    }

    /**
     * @param  callable(): HealthCheckResult  $check
     *
     * $monitoringType defaults to NotMonitored on purpose: an
     * unannotated registration is treated as unproven rather than
     * assumed real. Existing two-argument call sites (tests
     * overriding a check with a fixture) therefore keep working and
     * stay conservatively classified.
     */
    public function register(
        HealthCheckType $type,
        callable $check,
        HealthCheckMonitoringType $monitoringType = HealthCheckMonitoringType::NotMonitored,
    ): void {
        $this->checks[$type->value] = $check;
        $this->monitoringTypes[$type->value] = $monitoringType;
    }

    public function isRegistered(HealthCheckType $type): bool
    {
        return isset($this->checks[$type->value]);
    }

    /**
     * The declared provenance of one check. A HealthCheckType that is
     * not registered at all is NotMonitored — there is no probe, so
     * there is nothing to claim.
     */
    public function monitoringTypeFor(HealthCheckType $type): HealthCheckMonitoringType
    {
        return $this->monitoringTypes[$type->value] ?? HealthCheckMonitoringType::NotMonitored;
    }

    /**
     * Dynamic registry census, keyed by monitoring type value, always
     * covering every case of HealthCheckType (registered or not) so
     * the totals reconcile. Callers render these numbers directly
     * instead of hardcoding a count that drifts the moment a check is
     * added, removed, or made real.
     *
     * @return array<string, int>
     */
    public function monitoringTypeCounts(): array
    {
        $counts = array_fill_keys(
            array_map(fn (HealthCheckMonitoringType $t): string => $t->value, HealthCheckMonitoringType::cases()),
            0,
        );

        foreach (HealthCheckType::cases() as $type) {
            $counts[$this->monitoringTypeFor($type)->value]++;
        }

        return $counts;
    }

    public function totalRegisteredCount(): int
    {
        return count($this->checks);
    }

    /**
     * Check types declared in the enum but never registered — no
     * callable exists, so nothing about them is observed at all.
     *
     * @return array<int, HealthCheckType>
     */
    public function unregisteredTypes(): array
    {
        return array_values(array_filter(
            HealthCheckType::cases(),
            fn (HealthCheckType $type): bool => ! $this->isRegistered($type),
        ));
    }

    /**
     * @return array<int, HealthCheckResult>
     */
    public function runAll(): array
    {
        return array_values(array_map(
            fn (HealthCheckType $type): HealthCheckResult => $this->run($type),
            array_filter(HealthCheckType::cases(), fn (HealthCheckType $type): bool => $this->isRegistered($type)),
        ));
    }

    public function run(HealthCheckType $type): ?HealthCheckResult
    {
        if (! $this->isRegistered($type)) {
            return null;
        }

        $result = ($this->checks[$type->value])();

        return $result->withMonitoringType($this->monitoringTypeFor($type));
    }

    private function registerDefaults(): void
    {
        $this->registerUnmonitored(
            HealthCheckType::WebUptime,
            'No external uptime provider is configured. Web availability is not observed by this platform.',
        );

        // Real evidence, but note what it actually measures: the depth
        // and age of the database queue tables. It does NOT observe
        // whether any worker process is alive — see the honest naming
        // and the separate worker-evidence reporting in
        // QueueWorkerEvidenceService. An empty queue and a dead worker
        // look identical from here.
        $this->register(HealthCheckType::QueueWorkers, function () {
            $pending = $this->queueHealth->pendingJobsCount();
            $failed = $this->queueHealth->failedJobsCount();
            $healthy = $this->queueHealth->isHealthy();

            return new HealthCheckResult(
                HealthCheckType::QueueWorkers,
                $healthy ? HealthCheckStatus::Healthy : HealthCheckStatus::Degraded,
                "queue backlog: pending={$pending} failed={$failed} (backlog only — worker liveness is not observed)",
            );
        }, HealthCheckMonitoringType::InternalMetric);

        $this->register(HealthCheckType::Scheduler, function () {
            $lastHeartbeat = $this->schedulerHealth->lastHeartbeatAt();

            if ($lastHeartbeat === null) {
                return new HealthCheckResult(
                    HealthCheckType::Scheduler,
                    HealthCheckStatus::Unhealthy,
                    'no scheduler heartbeat has ever been recorded — the scheduler is not running, or has never run, in this environment',
                );
            }

            $healthy = $this->schedulerHealth->isHealthy();
            $ageSeconds = max(0, now()->timestamp - $lastHeartbeat);

            return new HealthCheckResult(
                HealthCheckType::Scheduler,
                $healthy ? HealthCheckStatus::Healthy : HealthCheckStatus::Unhealthy,
                $healthy
                    ? "heartbeat seen {$ageSeconds}s ago"
                    : "last scheduler heartbeat was {$ageSeconds}s ago, beyond the staleness window",
            );
        }, HealthCheckMonitoringType::InternalMetric);

        $this->register(HealthCheckType::FailedJobs, function () {
            $count = $this->queueHealth->failedJobsCount();

            return new HealthCheckResult(
                HealthCheckType::FailedJobs,
                $count > 50 ? HealthCheckStatus::Unhealthy : ($count > 0 ? HealthCheckStatus::Degraded : HealthCheckStatus::Healthy),
                "{$count} failed job(s)",
            );
        }, HealthCheckMonitoringType::InternalMetric);

        $this->registerUnmonitored(
            HealthCheckType::Storage,
            'No storage provider probe is configured. Object-storage availability is not observed by this platform.',
        );

        $this->registerUnmonitored(
            HealthCheckType::EmailDelivery,
            'No email delivery probe is configured. Outbound mail deliverability is not observed by this platform.',
        );

        $this->registerUnmonitored(
            HealthCheckType::PaymentWebhooks,
            'No payment-webhook probe is configured. Payment webhook delivery is not observed by this platform.',
        );

        $this->registerUnmonitored(
            HealthCheckType::DocumentScanning,
            'No document-scanning probe is configured. Malware-scanning availability is not observed by this platform.',
        );

        $this->register(
            HealthCheckType::TenantIsolationAnomalies,
            fn () => $this->tenantIsolationAnomaly->checkForKnownAnomalyPatterns(),
            HealthCheckMonitoringType::InternalMetric,
        );
    }

    /**
     * Registers a check type for which no probe exists. The result is
     * always NotMonitored — never Healthy — and the detail says
     * plainly what is not being watched, so an operator reading the
     * row learns the gap rather than being reassured by it.
     */
    private function registerUnmonitored(HealthCheckType $type, string $detail): void
    {
        $this->register(
            $type,
            fn () => new HealthCheckResult($type, HealthCheckStatus::NotMonitored, $detail),
            HealthCheckMonitoringType::NotMonitored,
        );
    }
}
