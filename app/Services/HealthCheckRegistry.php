<?php

namespace App\Services;

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
 * modified). No real external monitoring provider is required (project
 * rule) — WebUptime/Storage/EmailDelivery/PaymentWebhooks/
 * DocumentScanning default to fakeable stub callables a caller can
 * override via register() in tests.
 */
class HealthCheckRegistry
{
    /**
     * @var array<string, callable(): HealthCheckResult>
     */
    private array $checks = [];

    public function __construct(
        private QueueHealthService $queueHealth,
        private SchedulerHealthService $schedulerHealth,
        private TenantIsolationAnomalyService $tenantIsolationAnomaly,
    ) {
        $this->registerDefaults();
    }

    /**
     * @param  callable(): HealthCheckResult  $check
     */
    public function register(HealthCheckType $type, callable $check): void
    {
        $this->checks[$type->value] = $check;
    }

    public function isRegistered(HealthCheckType $type): bool
    {
        return isset($this->checks[$type->value]);
    }

    /**
     * @return array<int, HealthCheckResult>
     */
    public function runAll(): array
    {
        return array_map(fn ($check) => $check(), array_values($this->checks));
    }

    public function run(HealthCheckType $type): ?HealthCheckResult
    {
        if (! $this->isRegistered($type)) {
            return null;
        }

        return ($this->checks[$type->value])();
    }

    private function registerDefaults(): void
    {
        $this->register(HealthCheckType::WebUptime, fn () => new HealthCheckResult(
            HealthCheckType::WebUptime,
            HealthCheckStatus::Healthy,
            'stub check — no real external uptime provider configured in this phase',
        ));

        $this->register(HealthCheckType::QueueWorkers, function () {
            $healthy = $this->queueHealth->isHealthy();

            return new HealthCheckResult(
                HealthCheckType::QueueWorkers,
                $healthy ? HealthCheckStatus::Healthy : HealthCheckStatus::Degraded,
                "pending={$this->queueHealth->pendingJobsCount()} failed={$this->queueHealth->failedJobsCount()}",
            );
        });

        $this->register(HealthCheckType::Scheduler, function () {
            $healthy = $this->schedulerHealth->isHealthy();

            return new HealthCheckResult(
                HealthCheckType::Scheduler,
                $healthy ? HealthCheckStatus::Healthy : HealthCheckStatus::Unhealthy,
                $healthy ? 'recent heartbeat seen' : 'no recent scheduler heartbeat',
            );
        });

        $this->register(HealthCheckType::FailedJobs, function () {
            $count = $this->queueHealth->failedJobsCount();

            return new HealthCheckResult(
                HealthCheckType::FailedJobs,
                $count > 50 ? HealthCheckStatus::Unhealthy : ($count > 0 ? HealthCheckStatus::Degraded : HealthCheckStatus::Healthy),
                "{$count} failed job(s)",
            );
        });

        $this->register(HealthCheckType::Storage, fn () => new HealthCheckResult(
            HealthCheckType::Storage,
            HealthCheckStatus::Healthy,
            'stub check — no real storage provider probe configured in this phase',
        ));

        $this->register(HealthCheckType::EmailDelivery, fn () => new HealthCheckResult(
            HealthCheckType::EmailDelivery,
            HealthCheckStatus::Healthy,
            'stub check — no real email provider probe configured in this phase',
        ));

        $this->register(HealthCheckType::PaymentWebhooks, fn () => new HealthCheckResult(
            HealthCheckType::PaymentWebhooks,
            HealthCheckStatus::Healthy,
            'stub check — no payment processor exists before Phase 6',
        ));

        $this->register(HealthCheckType::DocumentScanning, fn () => new HealthCheckResult(
            HealthCheckType::DocumentScanning,
            HealthCheckStatus::Healthy,
            'stub check — Phase 4 FakeVirusScanner has no external dependency to probe',
        ));

        $this->register(HealthCheckType::TenantIsolationAnomalies, function () {
            $result = $this->tenantIsolationAnomaly->checkForKnownAnomalyPatterns();

            return $result;
        });
    }
}
