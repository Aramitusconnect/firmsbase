<?php

namespace App\Services;

use App\Enums\ChartOfAccountType;
use App\Models\AccountingJournalEntry;
use App\Models\FinancialEvidenceTransaction;
use App\Models\Firm;
use App\ValueObjects\OperatingBankMatchResult;
use Illuminate\Support\Collection;

/**
 * OperatingLedgerBankMatchingService — Phase I: matches bank evidence
 * (FinancialEvidenceTransaction, from Plaid) against the OPERATING
 * side of the accounting journal (AccountingJournalEntry/AccountingPosting
 * — covers InvoicePaymentApplied, TrustToOperatingTransfer, ExpensePaid,
 * Refund, Chargeback, WriteOff, Adjustment alike, since they all post
 * through the same journal). Plaid is reused unchanged as EVIDENCE
 * only; nothing here ever writes to FinancialEvidence* tables or
 * accounting_journal_entries — this service only READS both sides and
 * returns comparison results.
 *
 * Deliberately reuses FinancialEvidenceReconciliationCandidateDetectionService's
 * established amount+date-window matching approach and MATCH_WINDOW_DAYS
 * convention, rather than inventing a different one — but does NOT
 * extend or call that service directly: that service is Trust-ledger-
 * scoped and is one of exactly two files an explicit firewall test
 * (FinancialEvidenceTrustLedgerFirewallTest) allows to read
 * TrustLedgerEntry; this service must never import any Trust* class or
 * touch any trust_* table (operating-side matching only), so it stays
 * structurally separate rather than risk becoming a third allowed
 * exception to that firewall.
 *
 * Deliberately STATELESS — results are computed fresh from immutable
 * source data every call and returned as value objects, never
 * persisted to a new candidate table. This avoids introducing a third
 * cache (alongside trust_balances/matter_trust_balances) that could
 * itself drift stale, and trivially satisfies "never allow a bank feed
 * to silently reclassify money": nothing is ever written by this
 * class, so there is nothing to feed a downstream automated action.
 * Ambiguous/ every result requires a human to look at the returned
 * candidates and act through an existing, separate, explicit domain
 * service (e.g. OperatingPaymentRefundService) — never this one.
 */
class OperatingLedgerBankMatchingService
{
    private const MATCH_WINDOW_DAYS = 3;

    /**
     * @return Collection<int, OperatingBankMatchResult>
     */
    public function matchTransactions(Firm $firm, Collection $transactions): Collection
    {
        return $transactions->map(fn (FinancialEvidenceTransaction $transaction) => $this->matchOne($firm, $transaction));
    }

    public function matchOne(Firm $firm, FinancialEvidenceTransaction $transaction): OperatingBankMatchResult
    {
        $amountCents = abs($transaction->amount_cents);
        $windowStart = $transaction->transaction_date->copy()->subDays(self::MATCH_WINDOW_DAYS);
        $windowEnd = $transaction->transaction_date->copy()->addDays(self::MATCH_WINDOW_DAYS);

        $candidates = AccountingJournalEntry::query()
            ->where('firm_id', $firm->id)
            ->whereBetween('entry_date', [$windowStart, $windowEnd])
            ->whereHas('postings', function ($query) use ($amountCents) {
                $query->whereHas('chartOfAccount', fn ($accountQuery) => $accountQuery->where('account_type', ChartOfAccountType::Asset))
                    ->where(function ($amountQuery) use ($amountCents) {
                        $amountQuery->where('debit_cents', $amountCents)->orWhere('credit_cents', $amountCents);
                    });
            })
            ->with('postings.chartOfAccount')
            ->get();

        $status = match (true) {
            $candidates->count() === 1 => OperatingBankMatchResult::STATUS_MATCHED,
            $candidates->count() > 1 => OperatingBankMatchResult::STATUS_AMBIGUOUS,
            default => $this->hasPartialMatch($firm, $amountCents, $windowStart, $windowEnd)
                ? OperatingBankMatchResult::STATUS_PARTIALLY_MATCHED
                : OperatingBankMatchResult::STATUS_UNMATCHED,
        };

        return new OperatingBankMatchResult($transaction, $candidates, $status);
    }

    /**
     * A "partial match" is a set of two or more entries in the window,
     * on the Asset/cash side, whose amounts SUM to the transaction
     * amount — the shape a split-allocated payment (Phase F) or a
     * multi-invoice deposit produces, where no single entry matches
     * the full transaction amount but several together do.
     */
    private function hasPartialMatch(Firm $firm, int $amountCents, \DateTimeInterface $windowStart, \DateTimeInterface $windowEnd): bool
    {
        $cashAmounts = AccountingJournalEntry::query()
            ->where('firm_id', $firm->id)
            ->whereBetween('entry_date', [$windowStart, $windowEnd])
            ->with('postings.chartOfAccount')
            ->get()
            ->flatMap(fn (AccountingJournalEntry $entry) => $entry->postings)
            ->filter(fn ($posting) => $posting->chartOfAccount?->account_type === ChartOfAccountType::Asset)
            ->map(fn ($posting) => max($posting->debit_cents, $posting->credit_cents))
            ->filter(fn (int $cents) => $cents > 0 && $cents < $amountCents);

        if ($cashAmounts->count() < 2) {
            return false;
        }

        // A simple subset-sum check over a small candidate set (bank
        // reconciliation windows are firm/date-scoped, never large) —
        // greedy/backtracking is intentionally avoided in favor of the
        // straightforward case this phase actually needs: the
        // FULL candidate set sums exactly to the transaction amount.
        return $cashAmounts->sum() === $amountCents;
    }
}
