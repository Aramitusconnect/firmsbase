<?php

declare(strict_types=1);

namespace App\Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * ProviderBillableCallCompleted — pipeline step 17
 * (checkpoint4-design-cost-control.md §2 step 17). Mirrors
 * `App\Integrations\Events\ProviderOutboundRequestCompleted`'s "hook,
 * not consumer" role exactly — dispatched by
 * `App\Integrations\Billing\ProviderBillableCallPipeline`, zero
 * listeners ship in this checkpoint. Carries only non-secret scalar
 * fields — never a raw provider response, never a credential.
 */
final class ProviderBillableCallCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $providerKey,
        public readonly string $product,
        public readonly string $billingOperation,
        public readonly bool $billable,
        public readonly bool $certain,
        public readonly ?int $estimatedPriceCents,
        public readonly int $firmIntegrationId,
        public readonly ?string $correlationId,
    ) {}
}
