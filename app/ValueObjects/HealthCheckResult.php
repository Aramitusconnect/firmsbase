<?php

namespace App\ValueObjects;

use App\Enums\HealthCheckMonitoringType;
use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;

/**
 * HealthCheckResult — returned by each registered health-check
 * callable in HealthCheckRegistry when run. Mirrors Phase 4's
 * ReadinessComponentResult shape/role exactly, one layer up (health
 * checks vs. matter readiness components).
 *
 * Operations Control Plane addition: $monitoringType. It defaults to
 * NotMonitored deliberately — a callable that says nothing about the
 * provenance of its own signal must not have monitoring claimed on
 * its behalf. HealthCheckRegistry stamps the authoritative, declared
 * monitoring type onto every result it runs via withMonitoringType(),
 * so the registry (not the individual closure) stays the single
 * source of truth for what is actually monitored.
 */
final readonly class HealthCheckResult
{
    public function __construct(
        public HealthCheckType $checkType,
        public HealthCheckStatus $status,
        public ?string $detail = null,
        public HealthCheckMonitoringType $monitoringType = HealthCheckMonitoringType::NotMonitored,
    ) {}

    public function withMonitoringType(HealthCheckMonitoringType $monitoringType): self
    {
        return new self($this->checkType, $this->status, $this->detail, $monitoringType);
    }
}
