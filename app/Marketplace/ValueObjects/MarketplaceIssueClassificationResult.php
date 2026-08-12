<?php

declare(strict_types=1);

namespace App\Marketplace\ValueObjects;

use App\Models\PracticeArea;

/**
 * MarketplaceIssueClassificationResult — Mission 3 (MyAttorney
 * Conversion + AI Intake), checkpoint 6. Read-only result of
 * MarketplaceIssueClassifierService::classify(). Mirrors
 * MarketplaceIntakeEligibility's own "always constructible, never a
 * bare boolean, never throws for an ordinary unavailable case" shape.
 *
 * A result is always a PROPOSAL a visitor may accept or override —
 * nothing about this value object, or the classifier that produces it,
 * ever creates a FirmLead/Client/Matter or ranks/selects a Firm. See
 * MarketplaceIssueClassifierService's own docblock for the full
 * boundary.
 */
final class MarketplaceIssueClassificationResult
{
    public function __construct(
        public readonly bool $available,
        public readonly ?PracticeArea $practiceArea = null,
        public readonly ?string $confidence = null,
        public readonly ?string $unavailableReason = null,
    ) {}

    public static function classified(PracticeArea $practiceArea, string $confidence): self
    {
        return new self(true, $practiceArea, $confidence);
    }

    /**
     * $unavailableReason is INTERNAL diagnostic detail only (logging/
     * tests) — never render it to a public visitor. The public-facing
     * surface must only ever show "we couldn't suggest a category —
     * please choose one yourself," matching this codebase's
     * established "collapse to false, never disclose why" convention.
     */
    public static function unavailable(string $unavailableReason): self
    {
        return new self(false, unavailableReason: $unavailableReason);
    }
}
