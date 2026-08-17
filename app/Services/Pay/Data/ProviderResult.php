<?php

declare(strict_types=1);

namespace App\Services\Pay\Data;

use App\Enums\ProviderOutcome;

/**
 * ProviderResult — FirmsVault Pay Gate A3 (v1.4 §7). The canonical,
 * provider-NEUTRAL result shape every PaymentProviderAdapter returns —
 * the exact shape the later Finix adapter (Gate B) must produce.
 *
 * `providerMetadata` is OPAQUE to PaymentCore: core may store or log it
 * (redacted), but must never inspect provider-specific metadata to make
 * a financial decision (v1.4 §7). The canonical fields are the entire
 * decision surface.
 */
final class ProviderResult
{
    /**
     * @param  array<string, mixed>  $providerMetadata
     */
    public function __construct(
        public readonly string $providerCommandUuid,
        public readonly ?string $providerResourceReference,
        public readonly ProviderOutcome $outcome,
        public readonly ?int $amountCents,
        public readonly string $currency,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly ?string $evidenceReference,
        public readonly array $providerMetadata = [],
    ) {}
}
