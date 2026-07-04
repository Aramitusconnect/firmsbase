<?php

namespace App\ValueObjects;

/**
 * OrganizationSuccessRollup — aggregates CustomerSuccessSnapshot data
 * across every member firm of an Organization. Read-only aggregate;
 * does not persist and does not expose document content.
 */
final readonly class OrganizationSuccessRollup
{
    /**
     * @param CustomerSuccessSnapshot[] $memberFirmSnapshots
     */
    public function __construct(
        public int $organizationId,
        public int $memberFirmCount,
        public float $averageScore,
        public int $atRiskFirmCount,
        public int $criticalFirmCount,
        public array $memberFirmSnapshots,
    ) {
    }
}
