<?php

declare(strict_types=1);

namespace App\Integrations\Data;

use App\Models\Firm;

/**
 * ProviderUsageAnomaly — `ProviderUsageAnomalyDetectionService::evaluate()`'s
 * return value (checkpoint4-design-cost-control.md §7). Carries only the
 * scalar evidence needed to record and display an anomaly — never a
 * hydrated collection of the underlying `integration_usage_records`
 * rows that triggered it.
 */
final class ProviderUsageAnomaly
{
    public function __construct(
        public readonly Firm $firm,
        public readonly string $providerKey,
        public readonly string $product,
        public readonly int $currentWindowCount,
        public readonly float $baselineDailyAverage,
        public readonly bool $coldStart = false,
    ) {}
}
