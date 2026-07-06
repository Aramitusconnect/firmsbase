<?php

namespace App\ValueObjects;

use App\Enums\GovernanceGapSeverity;

/**
 * GapRegisterItem — one declared gap from ComplianceGapRegistryService.
 * Static/declarative — no gap_register table exists; this is the
 * entire register.
 */
final readonly class GapRegisterItem
{
    public function __construct(
        public string $key,
        public string $area,
        public string $description,
        public GovernanceGapSeverity $severity,
        public string $suggested_owning_gate,
        public string $status,
    ) {
    }
}
