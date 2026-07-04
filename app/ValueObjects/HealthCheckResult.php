<?php

namespace App\ValueObjects;

use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;

/**
 * HealthCheckResult — returned by each registered health-check
 * callable in HealthCheckRegistry when run. Mirrors Phase 4's
 * ReadinessComponentResult shape/role exactly, one layer up (health
 * checks vs. matter readiness components).
 */
final readonly class HealthCheckResult
{
    public function __construct(
        public HealthCheckType $checkType,
        public HealthCheckStatus $status,
        public ?string $detail = null,
    ) {
    }
}
