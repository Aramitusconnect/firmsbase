<?php

namespace App\ValueObjects;

use App\Enums\GovernanceMappingStatus;

/**
 * GovernanceMappingResult — one declared mapping item from a
 * cross-cutting mapping service (SecurityBaselineMappingService,
 * ComplianceReviewGateMappingService, AccessibilityCoverageMappingService).
 * Purely declarative: mapping this item to an owning_class is a
 * documentation claim, not an enforcement guarantee.
 */
final readonly class GovernanceMappingResult
{
    public function __construct(
        public string $item_key,
        public string $item_label,
        public ?string $owning_class,
        public GovernanceMappingStatus $status,
        public ?string $notes = null,
    ) {
    }
}
