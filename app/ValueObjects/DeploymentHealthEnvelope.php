<?php

namespace App\ValueObjects;

use App\Enums\DeploymentHealthReportMode;
use App\Enums\HealthCheckStatus;

/**
 * DeploymentHealthEnvelope — the minimum health envelope for a private
 * deployment (Master Plan §23 Scope): anonymized heartbeat, version,
 * migration status. "Anonymized" means the heartbeat itself carries no
 * PII, firm name, or client/matter data — just a timestamp and status;
 * this value object's own fields never include anything beyond that.
 */
final readonly class DeploymentHealthEnvelope
{
    public function __construct(
        public \DateTimeInterface $heartbeatAt,
        public string $version,
        public string $migrationStatus,
        public HealthCheckStatus $status,
        public DeploymentHealthReportMode $reportedVia,
    ) {
    }
}
