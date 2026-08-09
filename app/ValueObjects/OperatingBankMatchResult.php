<?php

namespace App\ValueObjects;

use App\Models\AccountingJournalEntry;
use App\Models\FinancialEvidenceTransaction;
use Illuminate\Support\Collection;

/**
 * OperatingBankMatchResult — Phase I. A pure, immutable read-only
 * result: which operating-journal entries (if any) plausibly
 * correspond to a given bank evidence transaction, and how confident
 * that correspondence is. Never itself a database row — see
 * OperatingLedgerBankMatchingService's own docblock for why nothing
 * is persisted.
 */
final readonly class OperatingBankMatchResult
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_PARTIALLY_MATCHED = 'partially_matched';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_UNMATCHED = 'unmatched';

    /**
     * @param  Collection<int, AccountingJournalEntry>  $candidateEntries
     */
    public function __construct(
        public FinancialEvidenceTransaction $transaction,
        public Collection $candidateEntries,
        public string $status,
    ) {
    }
}
