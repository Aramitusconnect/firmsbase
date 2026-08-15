<?php

namespace App\Services;

use App\Enums\HealthCheckMonitoringType;
use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Enums\OperationsFreshness;
use App\Models\HealthCheck;
use App\ValueObjects\ServiceHealthCurrentState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * OperationsHealthEvaluationService — turns the append-only
 * `health_checks` history into the current, freshness-aware state of
 * each registered check. Operations Control Plane addition.
 *
 * This is the service the Operations console reads. It exists
 * because the raw table cannot answer the questions an operator
 * actually has: the table is a log of thousands of observations, and
 * "is this check healthy right now" requires knowing the latest row,
 * whether that row is recent enough to still mean anything, and what
 * kind of evidence produced it. Rendering the log directly (as the
 * Service Health page previously did) answers none of those.
 *
 * Design rules, all load-bearing:
 *
 *  - Nothing is inferred. Every derived field comes from recorded
 *    rows; where no rows exist the field is null, never zero.
 *  - Staleness beats status. A Healthy row older than the freshness
 *    threshold is surfaced as Unknown, not Healthy (see
 *    ServiceHealthCurrentState::effectiveStatus()).
 *  - Monitoring type comes from the registry, which is authoritative,
 *    and falls back to whatever was persisted on the observation
 *    itself for historical rows written before that was recorded.
 *  - Reads are bounded. Derived history fields are computed from the
 *    most recent HISTORY_WINDOW observations per check, so this stays
 *    a fixed, small number of indexed queries no matter how large the
 *    table grows.
 *
 * `health_checks` carries FORCE ROW LEVEL SECURITY with the
 * "nullable-firm_id, universal read" two-policy shape — firm_id IS
 * NULL rows are readable regardless of active tenant context, so the
 * platform-wide reads below need no context wrap (same reasoning as
 * PlatformServiceHealthPage's own docblock).
 */
class OperationsHealthEvaluationService
{
    /**
     * The registered cadence of `health:checks:run` in
     * bootstrap/app.php (->everyFiveMinutes()). Kept as a constant
     * rather than read from the Schedule object at render time
     * because resolving the console schedule requires bootstrapping
     * the console application on every page load. Drift is prevented
     * by a regression test that asserts this constant still matches
     * the real registered expression — see
     * OperationsHealthFreshnessTest.
     */
    public const EXPECTED_CADENCE_SECONDS = 300;

    /**
     * Grace multiplier applied to the expected cadence before an
     * observation is called stale. Three missed sweeps is a
     * deliberate, conservative choice: it tolerates one slow or
     * skipped run without crying wolf, while still surfacing a
     * genuinely stopped monitoring pipeline within ~15 minutes.
     */
    public const FRESHNESS_GRACE_MULTIPLIER = 3;

    /**
     * Upper bound on observations inspected per check when computing
     * consecutive failures, last change, last success and last
     * failure. Disclosed in the UI so a truncated answer is never
     * mistaken for a complete one.
     */
    public const HISTORY_WINDOW = 100;

    public function __construct(private HealthCheckRegistry $registry) {}

    public function expectedCadenceSeconds(): int
    {
        return self::EXPECTED_CADENCE_SECONDS;
    }

    public function freshnessThresholdSeconds(): int
    {
        return self::EXPECTED_CADENCE_SECONDS * self::FRESHNESS_GRACE_MULTIPLIER;
    }

    /**
     * Current state for every check type in the closed
     * HealthCheckType enum — including types that are registered but
     * have never run, and types that are not registered at all. The
     * list is deliberately exhaustive: a check missing from the
     * console because it never produced a row is a monitoring gap
     * hidden by omission.
     *
     * @return array<int, ServiceHealthCurrentState>
     */
    public function currentStates(): array
    {
        return array_map(
            fn (HealthCheckType $type): ServiceHealthCurrentState => $this->currentStateFor($type),
            HealthCheckType::cases(),
        );
    }

    public function currentStateFor(HealthCheckType $type): ServiceHealthCurrentState
    {
        /** @var Collection<int, HealthCheck> $history */
        $history = HealthCheck::query()
            ->whereNull('firm_id')
            ->where('check_type', $type->value)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_WINDOW)
            ->get();

        $registryMonitoringType = $this->registry->monitoringTypeFor($type);
        $latest = $history->first();

        if ($latest === null) {
            return new ServiceHealthCurrentState(
                checkType: $type,
                status: $this->registry->isRegistered($type)
                    ? HealthCheckStatus::Unknown
                    : HealthCheckStatus::NotMonitored,
                monitoringType: $registryMonitoringType,
                freshness: OperationsFreshness::NeverObserved,
                detail: $this->registry->isRegistered($type)
                    ? 'Registered, but no observation has ever been recorded.'
                    : 'No check is registered for this surface.',
                lastCheckedAt: null,
                observationAgeSeconds: null,
                lastSuccessAt: null,
                lastFailureAt: null,
                lastChangedAt: null,
                consecutiveFailures: null,
                expectedCadenceSeconds: $this->expectedCadenceSeconds(),
                freshnessThresholdSeconds: $this->freshnessThresholdSeconds(),
                observationsInWindow: 0,
            );
        }

        $ageSeconds = max(0, now()->diffInSeconds($latest->checked_at, absolute: true));

        return new ServiceHealthCurrentState(
            checkType: $type,
            status: $latest->status,
            monitoringType: $this->resolveMonitoringType($type, $latest, $registryMonitoringType),
            freshness: $this->freshnessFor($ageSeconds),
            detail: $latest->detail,
            lastCheckedAt: $latest->checked_at,
            observationAgeSeconds: $ageSeconds,
            lastSuccessAt: $this->latestMatching($history, fn (HealthCheck $c): bool => $c->status->isObservedPassing()),
            lastFailureAt: $this->latestMatching($history, fn (HealthCheck $c): bool => $c->status->isObservedProblem()),
            lastChangedAt: $this->lastChangedAt($history),
            consecutiveFailures: $this->consecutiveFailures($history),
            expectedCadenceSeconds: $this->expectedCadenceSeconds(),
            freshnessThresholdSeconds: $this->freshnessThresholdSeconds(),
            observationsInWindow: $history->count(),
        );
    }

    /**
     * The registry is authoritative for what is monitored TODAY.
     * Historical rows persisted their own monitoring type in
     * `metadata_json`, which is used only when the type is no longer
     * registered at all — otherwise a check that was made real would
     * keep reporting its old, stale provenance.
     */
    private function resolveMonitoringType(
        HealthCheckType $type,
        HealthCheck $latest,
        HealthCheckMonitoringType $registryType,
    ): HealthCheckMonitoringType {
        if ($this->registry->isRegistered($type)) {
            return $registryType;
        }

        $persisted = $latest->metadata_json['monitoring_type'] ?? null;

        return is_string($persisted)
            ? (HealthCheckMonitoringType::tryFrom($persisted) ?? HealthCheckMonitoringType::NotMonitored)
            : HealthCheckMonitoringType::NotMonitored;
    }

    private function freshnessFor(int $ageSeconds): OperationsFreshness
    {
        return $ageSeconds <= $this->freshnessThresholdSeconds()
            ? OperationsFreshness::Fresh
            : OperationsFreshness::Stale;
    }

    /**
     * @param  Collection<int, HealthCheck>  $history
     * @param  callable(HealthCheck): bool  $matcher
     */
    private function latestMatching($history, callable $matcher): ?Carbon
    {
        $match = $history->first($matcher);

        return $match?->checked_at;
    }

    /**
     * When the status last actually changed: the checked_at of the
     * oldest observation in the current unbroken run of the latest
     * status. Returns null when the whole inspected window shares one
     * status — the true change point is older than the window, and
     * guessing would be fabrication.
     *
     * @param  Collection<int, HealthCheck>  $history
     */
    private function lastChangedAt($history): ?Carbon
    {
        $latestStatus = $history->first()->status;
        $runStart = null;

        foreach ($history as $observation) {
            if ($observation->status !== $latestStatus) {
                return $runStart;
            }

            $runStart = $observation->checked_at;
        }

        return null;
    }

    /**
     * Consecutive observed problems, most recent first. Zero is a
     * real measured answer here (the latest observation is not a
     * problem), which is why the null case is handled by the caller
     * for "no history at all" rather than collapsed into 0.
     *
     * @param  Collection<int, HealthCheck>  $history
     */
    private function consecutiveFailures($history): int
    {
        $count = 0;

        foreach ($history as $observation) {
            if (! $observation->status->isObservedProblem()) {
                break;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Platform-wide roll-up over every check's current state. Counts
     * are kept in separate buckets on purpose — collapsing
     * "not monitored" or "stale" into either healthy or unhealthy is
     * precisely the summarisation that makes a console lie.
     *
     * @return array{overall: HealthCheckStatus, healthy: int, degraded: int, critical: int, unknown: int, not_monitored: int, stale: int, never_observed: int, requires_attention: int, total: int}
     */
    public function summary(): array
    {
        $states = $this->currentStates();

        $healthy = $degraded = $critical = $unknown = $notMonitored = 0;
        $stale = $neverObserved = $requiresAttention = 0;

        foreach ($states as $state) {
            match ($state->effectiveStatus()) {
                HealthCheckStatus::Healthy => $healthy++,
                HealthCheckStatus::Degraded => $degraded++,
                HealthCheckStatus::Unhealthy => $critical++,
                HealthCheckStatus::NotMonitored => $notMonitored++,
                HealthCheckStatus::Unknown => $unknown++,
            };

            if ($state->freshness === OperationsFreshness::Stale) {
                $stale++;
            }

            if ($state->freshness === OperationsFreshness::NeverObserved) {
                $neverObserved++;
            }

            if ($state->requiresAttention()) {
                $requiresAttention++;
            }
        }

        return [
            'overall' => $this->overallStatus($critical, $degraded, $unknown, $healthy),
            'healthy' => $healthy,
            'degraded' => $degraded,
            'critical' => $critical,
            'unknown' => $unknown,
            'not_monitored' => $notMonitored,
            'stale' => $stale,
            'never_observed' => $neverObserved,
            'requires_attention' => $requiresAttention,
            'total' => count($states),
        ];
    }

    /**
     * The single overall verdict, worst-first. Critically, Healthy is
     * only ever returned when at least one check actually observed a
     * passing state AND nothing is unknown/stale — a platform whose
     * every signal is unmonitored reports Unknown, never Healthy,
     * because there is no evidence either way.
     */
    private function overallStatus(int $critical, int $degraded, int $unknown, int $healthy): HealthCheckStatus
    {
        if ($critical > 0) {
            return HealthCheckStatus::Unhealthy;
        }

        if ($degraded > 0) {
            return HealthCheckStatus::Degraded;
        }

        if ($unknown > 0) {
            return HealthCheckStatus::Unknown;
        }

        if ($healthy > 0) {
            return HealthCheckStatus::Healthy;
        }

        return HealthCheckStatus::NotMonitored;
    }

    /**
     * Every check currently needing operator action, with its reason.
     * Feeds the Requires Attention queue.
     *
     * @return array<int, ServiceHealthCurrentState>
     */
    public function requiringAttention(): array
    {
        return array_values(array_filter(
            $this->currentStates(),
            fn (ServiceHealthCurrentState $state): bool => $state->requiresAttention(),
        ));
    }

    /**
     * Monitored surfaces that this platform knowingly does not watch.
     * Reported separately from failures: these are accepted gaps
     * pending the external-monitoring decision, not live alerts — but
     * they must stay visible, because an invisible gap eventually
     * gets mistaken for coverage.
     *
     * @return array<int, ServiceHealthCurrentState>
     */
    public function monitoringGaps(): array
    {
        return array_values(array_filter(
            $this->currentStates(),
            fn (ServiceHealthCurrentState $state): bool => $state->monitoringType === HealthCheckMonitoringType::NotMonitored,
        ));
    }
}
