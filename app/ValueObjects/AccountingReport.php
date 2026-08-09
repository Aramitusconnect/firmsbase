<?php

namespace App\ValueObjects;

/**
 * AccountingReport — Phase J. The single, uniform envelope every
 * AccountingReportingService method returns, satisfying the master
 * prompt's own requirement that "every report must identify: firm,
 * reporting period, source records, generated time." $data holds the
 * report-specific payload (a Collection, array, or summary array) —
 * intentionally untyped since different reports have genuinely
 * different shapes; the envelope fields around it are what stay
 * uniform.
 */
final readonly class AccountingReport
{
    public function __construct(
        public int $firmId,
        public string $reportType,
        public ?\DateTimeInterface $periodStart,
        public ?\DateTimeInterface $periodEnd,
        public mixed $data,
        public \DateTimeInterface $generatedAt,
    ) {
    }
}
