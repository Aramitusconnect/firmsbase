<?php

declare(strict_types=1);

namespace App\Marketplace\Exceptions;

/**
 * MarketplaceIntakeIneligibleException — Mission 3 (MyAttorney
 * Conversion + AI Intake), checkpoint 3. Thrown by
 * MarketplaceIntakeService::startForDirectoryFirm() when
 * MarketplaceIntakeEligibilityService::evaluate() returns ineligible.
 * The exception message is an internal reason code, never shown to a
 * public visitor as-is — a caller-facing surface must catch this and
 * render its own generic "not currently accepting secure intake"
 * copy, matching MarketplaceIntakeEligibility's own docblock.
 */
class MarketplaceIntakeIneligibleException extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct("This firm is not eligible for secure intake: {$reasonCode}");
    }
}
