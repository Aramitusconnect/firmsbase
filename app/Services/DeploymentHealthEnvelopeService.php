<?php

namespace App\Services;

use App\Enums\DeploymentHealthReportMode;
use App\Enums\HealthCheckStatus;
use App\Models\DeploymentHealthCheck;
use App\Models\Firm;
use App\Models\PrivateEnterpriseSettings;
use App\Services\TenantContextService;
use App\ValueObjects\DeploymentHealthEnvelope;

/**
 * DeploymentHealthEnvelopeService — the only writer of
 * deployment_health_checks. Records exactly the minimum contractual
 * envelope (Master Plan §23): anonymized heartbeat, version, migration
 * status. If private_enterprise_settings.telemetry_prohibited is true
 * for the firm, reported_via is FORCED to offline_report (project
 * rule 16) — the row is still written locally either way; "offline"
 * means no outbound telemetry call is made, never that health
 * reporting stops. No network call of any kind exists in this
 * service.
 */
class DeploymentHealthEnvelopeService
{
    public function __construct(private readonly VersionSkewPolicyService $versionSkewPolicy)
    {
    }

    public function buildEnvelope(Firm $firm, string $version, string $saasVersion, ?string $migrationStatus = null): DeploymentHealthEnvelope
    {
        // private_enterprise_settings now carries permanent FORCE ROW
        // LEVEL SECURITY, so this read is wrapped narrowly in the
        // firm's own tenant context (whole-call wrap, not just the
        // argument) rather than relying on any ambient context that
        // may or may not already be active at this call site. Queried
        // directly by firm_id instead of trusting the cached
        // $firm->privateEnterpriseSettings relation, to avoid a
        // stale-cache hazard. The DeploymentHealthCheck::create() call
        // below is intentionally left unwrapped — it writes to the
        // separate, still-unprepared deployment_health_checks table
        // and must stay decoupled from this table's context.
        $telemetryProhibited = (new TenantContextService())->runWithFirmContext(
            $firm,
            fn () => (bool) (PrivateEnterpriseSettings::query()->where('firm_id', $firm->id)->first()?->telemetry_prohibited ?? false),
        );
        $reportMode = $telemetryProhibited ? DeploymentHealthReportMode::OfflineReport : DeploymentHealthReportMode::Live;

        $skew = $this->versionSkewPolicy->check($version, $saasVersion);
        $status = $skew->withinPolicy ? HealthCheckStatus::Healthy : HealthCheckStatus::Degraded;

        $heartbeatAt = now();

        DeploymentHealthCheck::create([
            'firm_id' => $firm->id,
            'heartbeat_at' => $heartbeatAt,
            'version' => $version,
            'migration_status' => $migrationStatus,
            'status' => $status,
            'reported_via' => $reportMode,
        ]);

        return new DeploymentHealthEnvelope(
            heartbeatAt: $heartbeatAt,
            version: $version,
            migrationStatus: $migrationStatus ?? 'unknown',
            status: $status,
            reportedVia: $reportMode,
        );
    }

    /**
     * Explicit offline fallback — always writes reported_via=offline_report
     * regardless of the firm's settings, for a caller that already
     * knows telemetry cannot be attempted right now. Still just a local
     * row write; no network call.
     */
    public function reportOffline(Firm $firm, string $version, ?string $migrationStatus = null, HealthCheckStatus $status = HealthCheckStatus::Unknown): DeploymentHealthCheck
    {
        return DeploymentHealthCheck::create([
            'firm_id' => $firm->id,
            'heartbeat_at' => now(),
            'version' => $version,
            'migration_status' => $migrationStatus,
            'status' => $status,
            'reported_via' => DeploymentHealthReportMode::OfflineReport,
        ]);
    }
}
