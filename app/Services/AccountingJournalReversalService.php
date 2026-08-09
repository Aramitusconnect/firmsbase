<?php

namespace App\Services;

use App\Models\AccountingJournalEntry;
use App\Models\Firm;
use App\Models\FirmUser;
use Illuminate\Support\Facades\DB;

/**
 * AccountingJournalReversalService — the ONLY sanctioned way to correct
 * an already-posted AccountingJournalEntry, mirroring
 * TrustLedgerEntryReversalService exactly: never mutates the original
 * entry or its postings, only creates a brand-new entry with every
 * posting line's debit/credit swapped (the standard reversing-entry
 * technique — a balanced entry reversed is still balanced), linked via
 * reverses_journal_entry_id. No partial reversal — the same
 * conservative, full-entry-only choice already made for trust
 * chargebacks.
 */
class AccountingJournalReversalService
{
    public function reverse(Firm $firm, AccountingJournalEntry $original, string $reason, ?FirmUser $reversedBy = null): AccountingJournalEntry
    {
        if ($original->firm_id !== $firm->id) {
            throw new \RuntimeException('The journal entry being reversed does not belong to this firm.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $original, $reason, $reversedBy) {
            return DB::transaction(function () use ($firm, $original, $reason, $reversedBy) {
                if (AccountingJournalEntry::query()->where('reverses_journal_entry_id', $original->id)->exists()) {
                    throw new \RuntimeException('This journal entry has already been reversed.');
                }

                $postings = $original->postings()->get();

                if ($postings->isEmpty()) {
                    throw new \RuntimeException('Cannot reverse a journal entry with no posting lines.');
                }

                $reversal = AccountingJournalEntry::create([
                    'firm_id' => $firm->id,
                    'entry_date' => now()->toDateString(),
                    'description' => "Reversal: {$reason}",
                    'source_type' => $original->source_type,
                    'reverses_journal_entry_id' => $original->id,
                    'posted_by_firm_user_id' => $reversedBy?->id,
                    'payment_id' => $original->payment_id,
                    'invoice_id' => $original->invoice_id,
                    'expense_id' => $original->expense_id,
                    'trust_transfer_request_id' => $original->trust_transfer_request_id,
                    'created_at' => now(),
                ]);

                foreach ($postings as $posting) {
                    $reversal->postings()->create([
                        'firm_id' => $firm->id,
                        'chart_of_account_id' => $posting->chart_of_account_id,
                        'client_id' => $posting->client_id,
                        'matter_id' => $posting->matter_id,
                        // Swapped: a debit becomes a credit and vice versa.
                        'debit_cents' => $posting->credit_cents,
                        'credit_cents' => $posting->debit_cents,
                        'memo' => $posting->memo,
                    ]);
                }

                return $reversal->fresh('postings');
            });
        });
    }
}
