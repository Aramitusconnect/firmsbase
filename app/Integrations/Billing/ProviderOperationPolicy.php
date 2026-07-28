<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

/**
 * ProviderOperationPolicy — the resolved, effective per-firm operation
 * policy `ProviderOperationPolicyResolver::resolve()` returns (pipeline
 * step 7, checkpoint4-design-cost-control.md §2 step 7). Every field is
 * resolved firm-row-value-if-set, else the global default row's value,
 * else a hard fallback — never a silent zero.
 */
final class ProviderOperationPolicy
{
    public function __construct(
        public readonly ?int $softLimitQuantity,
        public readonly ?int $hardLimitQuantity,
        public readonly int $limitWindowSeconds,
        public readonly int $cooldownSeconds,
        public readonly ?int $cacheTtlSeconds,
    ) {}
}
