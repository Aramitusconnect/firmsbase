<?php

namespace App\ValueObjects;

use App\Enums\HealthCheckMonitoringType;
use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Enums\OperationsFreshness;
use Illuminate\Support\Carbon;

/**
 * ServiceHealthCurrentState — the current, freshness-aware state of
 * ONE registered health check, assembled by
 * OperationsHealthEvaluationService from the append-only
 * `health_checks` history plus the registry's declared monitoring
 * type. Operations Control Plane addition.
 *
 * Every field is either measured from real recorded rows or
 * explicitly absent. Nothing here is inferred from configuration,
 * and nothing is defaulted to a reassuring value: a check with no
 * history at all reports NeverObserved with null timestamps rather
 * than zeroes, because "0 failures" and "no data" are different
 * answers and only one of them is good news.
 */
final readonly class ServiceHealthCurrentState
{
    /**
     * @param  int|null  $consecutiveFailures  Null when no history exists at all — never 0, which would read as "no failures observed."
     * @param  int|null  $observationsInWindow  Number of recorded observations the derived fields were computed from.
     */
    public function __construct(
        public HealthCheckType $checkType,
        public HealthCheckStatus $status,
        public HealthCheckMonitoringType $monitoringType,
        public OperationsFreshness $freshness,
        public ?string $detail,
        public ?Carbon $lastCheckedAt,
        public ?int $observationAgeSeconds,
        public ?Carbon $lastSuccessAt,
        public ?Carbon $lastFailureAt,
        public ?Carbon $lastChangedAt,
        public ?int $consecutiveFailures,
        public int $expectedCadenceSeconds,
        public int $freshnessThresholdSeconds,
        public ?int $observationsInWindow,
    ) {}

    public function hasHistory(): bool
    {
        return $this->lastCheckedAt !== null;
    }

    /**
     * The status to SHOW an operator. A stale observation is never
     * presented as the current state — a Healthy row from six hours
     * ago becomes Unknown, because nobody actually knows. This is the
     * single most important method on this object: it is what stops a
     * frozen monitoring pipeline from looking like a healthy platform.
     */
    public function effectiveStatus(): HealthCheckStatus
    {
        if ($this->status === HealthCheckStatus::NotMonitored) {
            return HealthCheckStatus::NotMonitored;
        }

        if ($this->freshness === OperationsFreshness::NeverObserved) {
            return HealthCheckStatus::Unknown;
        }

        if ($this->freshness === OperationsFreshness::Stale) {
            return HealthCheckStatus::Unknown;
        }

        return $this->status;
    }

    /**
     * True when this check is currently something an operator should
     * act on: an observed problem, or a monitored check whose
     * evidence has gone stale/absent. An honestly-unmonitored check
     * is a known, accepted gap rather than a live alert, so it does
     * not raise attention on its own here — it is reported separately
     * as a monitoring gap.
     */
    public function requiresAttention(): bool
    {
        if ($this->monitoringType === HealthCheckMonitoringType::NotMonitored) {
            return false;
        }

        if ($this->status->isObservedProblem()) {
            return true;
        }

        return ! $this->freshness->isTrustworthyAsCurrent();
    }

    /**
     * A short, operator-facing reason this check needs attention, or
     * null when it does not. Used verbatim by the Requires Attention
     * queue so the reason shown is always derived from the same
     * evidence as the decision.
     */
    public function attentionReason(): ?string
    {
        if (! $this->requiresAttention()) {
            return null;
        }

        return match (true) {
            $this->freshness === OperationsFreshness::NeverObserved => 'Monitored check has never recorded an observation.',
            $this->freshness === OperationsFreshness::Stale => sprintf(
                'Last observation is %ds old, beyond the %ds freshness threshold — current state is unknown.',
                $this->observationAgeSeconds ?? 0,
                $this->freshnessThresholdSeconds,
            ),
            $this->status === HealthCheckStatus::Unhealthy => 'Check reported a critical failure.',
            $this->status === HealthCheckStatus::Degraded => 'Check reported a degraded state.',
            default => 'Check requires review.',
        };
    }
}
