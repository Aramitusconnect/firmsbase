<?php

namespace App\Services;

use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Models\Firm;
use App\Models\HealthCheck;

/**
 * HealthCheckService — persists a health_checks row (append-only) for
 * each HealthCheckResult produced by a full HealthCheckRegistry run.
 * firm_id stays null for every default registered check except
 * TenantIsolationAnomalies, which is written per-firm when a specific
 * firm is being checked.
 *
 * TenantIsolationAnomalyService::recordAnomaly() is the one other
 * writer to health_checks — it records an anomaly the moment it is
 * detected (out-of-band, outside the regular runAllAndRecord() cycle)
 * rather than waiting for the next scheduled check run, since an
 * isolation anomaly is urgent enough that recomputing on a delay would
 * defeat the point.
 */
class HealthCheckService
{
    public function __construct(private HealthCheckRegistry $registry) {}

    /**
     * Read phase runs under one context (matching $firm) so that
     * checkForKnownAnomalyPatterns() (invoked transitively via
     * registry->runAll()) can only ever observe rows RLS permits for
     * that context. Write phase is then split by each result's own
     * destined firm_id: the 8 always-platform-wide results write under
     * runWithoutFirmContext(), and the one TenantIsolationAnomalies
     * result (only when $firm is given) writes under
     * runWithFirmContext($firm, ...). A single shared wrap for the
     * whole method would make the 8 null-firm_id writes fail the
     * asymmetric WITH CHECK whenever $firm is given.
     *
     * @return array<int, HealthCheck>
     */
    public function runAllAndRecord(?Firm $firm = null): array
    {
        $checkedAt = now();
        $tenantContext = app(TenantContextService::class);

        $readBody = fn () => $this->registry->runAll();

        $results = $firm
            ? $tenantContext->runWithFirmContext($firm, $readBody)
            : $tenantContext->runWithoutFirmContext($readBody);

        $platformResults = [];
        $firmResult = null;

        foreach ($results as $result) {
            if ($firm && $result->checkType === HealthCheckType::TenantIsolationAnomalies) {
                $firmResult = $result;
            } else {
                $platformResults[] = $result;
            }
        }

        $writePlatform = fn () => array_map(
            fn ($result) => HealthCheck::create([
                'firm_id' => null,
                'check_type' => $result->checkType,
                'status' => $result->status,
                'detail' => $result->detail,
                'checked_at' => $checkedAt,
                'metadata_json' => ['monitoring_type' => $result->monitoringType->value],
            ]),
            $platformResults
        );

        $created = $tenantContext->runWithoutFirmContext($writePlatform);

        if ($firmResult !== null) {
            $created[] = $tenantContext->runWithFirmContext($firm, fn () => HealthCheck::create([
                'firm_id' => $firm->id,
                'check_type' => $firmResult->checkType,
                'status' => $firmResult->status,
                'detail' => $firmResult->detail,
                'checked_at' => $checkedAt,
                'metadata_json' => ['monitoring_type' => $firmResult->monitoringType->value],
            ]));
        }

        return $created;
    }

    public function latestFor(HealthCheckType $type): ?HealthCheck
    {
        return HealthCheck::query()
            ->where('check_type', $type->value)
            ->latest('checked_at')
            ->first();
    }

    /**
     * "Monitoring and alerting are active" (acceptance criterion) —
     * true only when every registered check's most recent recorded
     * result is Healthy or Degraded (not Unhealthy/Unknown).
     *
     * Operations Control Plane note: NotMonitored is deliberately NOT
     * treated as a failure here, because an unmonitored surface is an
     * absence of evidence, not an observed fault — flipping this to
     * false would make every environment permanently "unhealthy" and
     * train operators to ignore it. It is equally NOT evidence of
     * health: this method answers only "has anything observable
     * broken," which is a narrower question than "is the platform
     * healthy." The broader question — which requires distinguishing
     * observed-good from nobody-looked from stale — is answered by
     * OperationsHealthEvaluationService::evaluate(), and that is what
     * the Operations console renders. Callers wanting an overall
     * verdict fit to show a human should use that service, not this
     * boolean.
     */
    public function isOverallHealthy(): bool
    {
        foreach (HealthCheckType::cases() as $type) {
            $latest = $this->latestFor($type);

            if (! $latest) {
                continue;
            }

            if (in_array($latest->status, [HealthCheckStatus::Unhealthy, HealthCheckStatus::Unknown], true)) {
                return false;
            }
        }

        return true;
    }
}
