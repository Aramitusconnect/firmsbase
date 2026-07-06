<?php

namespace App\Services;

use App\Enums\DeploymentHealthReportMode;
use App\Enums\HealthCheckStatus;
use App\Models\DeploymentHealthCheck;
use App\Models\Firm;
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
        $telemetryProhibited = (bool) ($firm->privateEnterpriseSettings?->telemetry_prohibited ?? false);
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
