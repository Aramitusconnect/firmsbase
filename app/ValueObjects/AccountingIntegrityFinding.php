<?php

namespace App\ValueObjects;

/**
 * AccountingIntegrityFinding — Accounting Integrity Hardening Pass,
 * item 10. One detected inconsistency. Read-only by construction: this
 * value object carries no method that could mutate anything, matching
 * AccountingIntegrityService's own "detect and report, never fix"
 * contract.
 */
class AccountingIntegrityFinding
{
    public function __construct(
        public readonly string $type,
        public readonly string $description,
        public readonly string $subjectType,
        public readonly int $subjectId,
    ) {}
}
