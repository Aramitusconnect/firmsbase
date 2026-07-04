<?php

namespace App\Services;

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
    public function __construct(private HealthCheckRegistry $registry)
    {
    }

    /**
     * @return array<int, HealthCheck>
     */
    public function runAllAndRecord(?Firm $firm = null): array
    {
        $checkedAt = now();

        return array_map(function ($result) use ($firm, $checkedAt) {
            return HealthCheck::create([
                'firm_id' => $result->checkType === \App\Enums\HealthCheckType::TenantIsolationAnomalies ? $firm?->id : null,
                'check_type' => $result->checkType,
                'status' => $result->status,
                'detail' => $result->detail,
                'checked_at' => $checkedAt,
            ]);
        }, $this->registry->runAll());
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
