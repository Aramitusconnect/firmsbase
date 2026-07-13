<?php

namespace App\Services;

use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Models\Firm;
use App\Services\TenantContextService;
use App\ValueObjects\HealthCheckResult;

/**
 * TenantIsolationAnomalyService — records and reports suspected
 * cross-tenant data leakage. Detection itself is out of scope for
 * Phase 5 (no AI, no automated static analysis is built here) — this
 * service provides the RECORDING and REPORTING half: recordAnomaly()
 * is called by any Phase 1-4 code path that notices a firm_id mismatch
 * (e.g. BelongsToTenant::assertBelongsToActiveTenant() throwing a
 * TenantIsolationException could be wired to call this in a later
 * phase), and checkForKnownAnomalyPatterns() is the fakeable read-time
 * check HealthCheckRegistry consults for the TenantIsolationAnomalies
 * check_type. No real static/dynamic analysis engine is required in
 * tests (project rule) — the "known pattern" check is a simple,
 * overridable query over already-recorded anomalies.
 */
class TenantIsolationAnomalyService
{
    /**
     * Firm is nullable to allow recording a platform-wide anomaly
     * (e.g. a query pattern that could affect every tenant), though in
     * practice most anomalies are firm-specific.
     *
     * Self-wraps its own body in the correct tenant context matching
     * $firm. Callers must NOT nest this call inside their own
     * runWithFirmContext()/runWithoutFirmContext() wrap — doing so
     * would let this method's own finally block clear the outer
     * caller's context prematurely (the "decoy wrap" bug class). Call
     * standalone; wrap only what runs before/after it.
     */
    public function recordAnomaly(?Firm $firm, string $description, array $metadata = []): \App\Models\HealthCheck
    {
        $create = fn () => \App\Models\HealthCheck::create([
            'firm_id' => $firm?->id,
            'check_type' => HealthCheckType::TenantIsolationAnomalies,
            'status' => HealthCheckStatus::Unhealthy,
            'detail' => $description,
            'checked_at' => now(),
            'metadata_json' => $metadata,
        ]);

        $tenantContext = app(TenantContextService::class);

        return $firm
            ? $tenantContext->runWithFirmContext($firm, $create)
            : $tenantContext->runWithoutFirmContext($create);
    }

    /**
     * Healthy unless an anomaly was recorded within the lookback
     * window. This is the default registered callable in
     * HealthCheckRegistry — tests can override it via register() with
     * a fake result.
     */
    public function checkForKnownAnomalyPatterns(int $lookbackMinutes = 60): HealthCheckResult
    {
        $recentAnomaly = \App\Models\HealthCheck::query()
            ->where('check_type', HealthCheckType::TenantIsolationAnomalies->value)
            ->where('status', HealthCheckStatus::Unhealthy->value)
            ->where('checked_at', '>=', now()->subMinutes($lookbackMinutes))
            ->latest('checked_at')
            ->first();

        if ($recentAnomaly) {
            return new HealthCheckResult(
                HealthCheckType::TenantIsolationAnomalies,
                HealthCheckStatus::Unhealthy,
                $recentAnomaly->detail ?? 'a tenant isolation anomaly was recently recorded',
            );
        }

        return new HealthCheckResult(
            HealthCheckType::TenantIsolationAnomalies,
            HealthCheckStatus::Healthy,
            'no tenant isolation anomalies recorded in the lookback window',
        );
    }
}
