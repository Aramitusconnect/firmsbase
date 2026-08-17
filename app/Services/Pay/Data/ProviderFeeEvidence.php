<?php

declare(strict_types=1);

namespace App\Services\Pay\Data;

use App\Enums\ProviderFeeDirection;

/**
 * ProviderFeeEvidence — FirmsVault Pay Gate A3 (v1.4 §36). The minimal
 * provider-neutral representation of one fee evidence line:
 *
 *     amount >= 0
 *     direction = DEBIT / CREDIT
 *     category nullable — UNKNOWN is permitted
 *     provider-native detail opaque
 *
 * Deliberately NOT a pricing engine (§48): this object records what a
 * provider REPORTED, it never computes what a provider should charge.
 * No posting is derived from it in Gate A3.
 */
final class ProviderFeeEvidence
{
    /**
     * @param  array<string, mixed>  $providerMetadata  opaque to core
     */
    public function __construct(
        public readonly int $amountCents,
        public readonly ProviderFeeDirection $direction,
        public readonly ?string $category,
        public readonly array $providerMetadata = [],
    ) {
        if ($amountCents < 0) {
            throw new \InvalidArgumentException(
                'Fee evidence amounts are magnitudes: amount_cents must be >= 0 (direction carries the sign); got '.$amountCents.'.'
            );
        }
    }

    /** UNKNOWN category is an allowed, honest state (v1.4 §36). */
    public function categoryOrUnknown(): string
    {
        return $this->category ?? 'unknown';
    }
}
