<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

/**
 * ProviderBillingClassification — the small, closed value object
 * `ProviderBillingClassifier::classify()` returns (pipeline step 5,
 * checkpoint4-design-cost-control.md §2 step 5). Deliberately separate
 * from `ProviderRequestExecutor::send()`'s own closed `operationType`
 * vocabulary (`push|pull|refresh_token|health_check|token_exchange|webhook_subscribe`)
 * — that axis exists purely for HEALTH tracking and has no billing
 * meaning; this axis is the BILLING classification, threaded into the
 * existing `capability` string parameter instead (§1.5 of the same
 * document).
 *
 * `isCacheable` is this pipeline's own addition, not explicitly named
 * as a `ProviderBillingClassification` field anywhere in the source
 * design (a real gap: §2 step 8 describes cache-check behavior —
 * "Only classifications explicitly marked cacheable... ever populate a
 * cache key" — without ever specifying where that mark lives). Filled
 * in here as a plain boolean on this value object, defaulting true for
 * every product except `('balance', *)`, matching the design's own
 * explicit statement that Balance's cache-check step is "always a
 * structural no-op" (Balance is documented real-time/never-cached by
 * Plaid itself).
 */
final class ProviderBillingClassification
{
    public function __construct(
        public readonly string $product,
        public readonly string $billingOperation,
        public readonly string $endpointCategory,
        public readonly bool $isOptional,
        public readonly bool $requiresExplicitConfirmation,
        public readonly bool $isCacheable,
    ) {}

    public function capability(): string
    {
        return "{$this->product}:{$this->billingOperation}";
    }
}
