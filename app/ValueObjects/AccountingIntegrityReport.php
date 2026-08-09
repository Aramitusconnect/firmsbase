<?php

namespace App\ValueObjects;

use Illuminate\Support\Collection;

/**
 * AccountingIntegrityReport — Accounting Integrity Hardening Pass, item
 * 10. One firm's integrity-check result.
 */
class AccountingIntegrityReport
{
    /**
     * @param  Collection<int, AccountingIntegrityFinding>  $findings
     */
    public function __construct(
        public readonly int $firmId,
        public readonly Collection $findings,
        public readonly \DateTimeInterface $generatedAt,
    ) {}

    public function isClean(): bool
    {
        return $this->findings->isEmpty();
    }
}
