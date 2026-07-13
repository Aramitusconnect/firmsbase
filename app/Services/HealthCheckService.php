<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\HealthCheck;
use App\Services\TenantContextService;

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
    public function __construct(private HealthCheckRegistry $registry)
    {
    }

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
            if ($firm && $result->checkType === \App\Enums\HealthCheckType::TenantIsolationAnomalies) {
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
            ]));
        }

        return $created;
    }

    public function latestFor(\App\Enums\HealthCheckType $type): ?HealthCheck
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
     */
    public function isOverallHealthy(): bool
    {
        foreach (\App\Enums\HealthCheckType::cases() as $type) {
            $latest = $this->latestFor($type);

            if (! $latest) {
                continue;
            }

            if (in_array($latest->status, [\App\Enums\HealthCheckStatus::Unhealthy, \App\Enums\HealthCheckStatus::Unknown], true)) {
                return false;
            }
        }

        return true;
    }
}
