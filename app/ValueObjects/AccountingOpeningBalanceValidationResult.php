<?php

namespace App\ValueObjects;

/**
 * AccountingOpeningBalanceValidationResult — Accounting Integrity
 * Hardening Pass, item 8. The dry-run output of
 * AccountingOpeningBalanceService::validate(): never persists anything,
 * so a firm's cutover lines can be checked repeatedly before the one
 * real, irreversible record() call.
 */
class AccountingOpeningBalanceValidationResult
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors,
        public readonly int $totalDebitCents,
        public readonly int $totalCreditCents,
        public readonly bool $alreadyRecorded,
    ) {}
}
