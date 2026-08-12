<?php

declare(strict_types=1);

namespace App\Marketplace\ValueObjects;

/**
 * MarketplaceIntakeEligibility — Mission 3 (MyAttorney Conversion +
 * AI Intake), checkpoint 3. Read-only result of
 * MarketplaceIntakeEligibilityService::evaluate(). reasonCode is
 * INTERNAL diagnostic detail only (logging/tests/Firm-side tooling) —
 * never render it to a public visitor; the public-facing surface must
 * only ever show "this firm is not currently accepting secure
 * intake," matching this codebase's established "collapse to false,
 * never disclose why" convention for anything a public,
 * unauthenticated visitor can probe.
 */
class MarketplaceIntakeEligibility
{
    public function __construct(
        public readonly bool $eligible,
        public readonly ?string $reasonCode = null,
    ) {}

    public static function eligible(): self
    {
        return new self(true);
    }

    public static function ineligible(string $reasonCode): self
    {
        return new self(false, $reasonCode);
    }
}
