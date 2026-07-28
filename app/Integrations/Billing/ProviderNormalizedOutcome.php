<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

/**
 * ProviderNormalizedOutcome — `ProviderCallOutcomeNormalizer::normalize()`'s
 * return value (pipeline step 14, checkpoint4-design-cost-control.md §2
 * step 14/§3.2). Drives the reservation's finalize transition:
 * `billable && certain` -> `finalized_billable`; `!billable && certain`
 * -> `finalized_non_billable`; `!certain` -> `finalized_uncertain`
 * (billable is meaningless/ignored when uncertain).
 */
final class ProviderNormalizedOutcome
{
    public function __construct(
        public readonly bool $billable,
        public readonly bool $certain,
        public readonly string $category,
    ) {}

    public static function success(): self
    {
        return new self(billable: true, certain: true, category: 'success');
    }

    public static function nonBillable(string $category): self
    {
        return new self(billable: false, certain: true, category: $category);
    }

    public static function uncertain(string $category): self
    {
        return new self(billable: false, certain: false, category: $category);
    }

    /**
     * A cache hit (pipeline step 8) never reaches the real outcome
     * normalizer at all — this factory exists purely so
     * `ProviderBillableCallPipeline::execute()` can still return one
     * uniform `ProviderBillableCallResult` shape for a served-from-cache
     * response, without inventing a second return type.
     */
    public static function servedFromCache(): self
    {
        return new self(billable: false, certain: true, category: 'served_from_cache');
    }
}
