<?php

namespace App\Services;

use App\Enums\AccountingJournalSourceType;
use App\Models\AccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Models\FirmUser;
use Illuminate\Support\Facades\DB;

/**
 * AccountingJournalPostingService — the ONLY writer of
 * accounting_journal_entries/accounting_postings. Enforces true
 * double-entry bookkeeping: every call posts one journal entry with
 * two or more posting lines whose debits and credits sum to the same
 * total, inside one transaction.
 *
 * This service is domain-agnostic by design — it never queries
 * Invoice/Payment/Expense/Trust* itself, only records the accounting
 * consequence a caller has already computed. It does not decide
 * WHETHER to post; the calling domain service decides that and
 * supplies the exact posting lines. This keeps AccountingJournalPostingService
 * from ever becoming a second place business rules are decided (that
 * stays in InvoiceDraftingService/PaymentApplicationService/Trust*
 * services, per the deduplication mandate).
 */
class AccountingJournalPostingService
{
    /**
     * @param  array<int, array{chart_of_account_id:int, debit_cents:int, credit_cents:int, client_id?:int|null, matter_id?:int|null, memo?:string|null}>  $postings
     * @param  array{payment_id?:int|null, invoice_id?:int|null, expense_id?:int|null, trust_transfer_request_id?:int|null}  $sourceRefs
     *
     * $idempotencyKey (project rule, Phase D): when supplied, a retry
     * with the SAME (firm_id, idempotency_key) returns the original
     * entry instead of posting a second one — the caller's own retry
     * (webhook redelivery, queued-job retry) must never double-post.
     * The partial unique index on (firm_id, idempotency_key) is the
     * concurrency-safe backstop if two requests race past the
     * check-then-create below, mirroring ManualPaymentService's own
     * idempotency pattern on payments.idempotency_key.
     */
    public function post(
        Firm $firm,
        AccountingJournalSourceType $sourceType,
        string $description,
        \DateTimeInterface $entryDate,
        array $postings,
        array $sourceRefs = [],
        ?FirmUser $postedBy = null,
        ?string $idempotencyKey = null,
    ): AccountingJournalEntry {
        if (count($postings) < 2) {
            throw new \InvalidArgumentException('A journal entry requires at least two posting lines.');
        }

        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($postings as $line) {
            $debit = $line['debit_cents'] ?? 0;
            $credit = $line['credit_cents'] ?? 0;

            if ($debit < 0 || $credit < 0) {
                throw new \InvalidArgumentException('Posting amounts must be non-negative; use the opposite column to represent direction.');
            }

            if ($debit > 0 && $credit > 0) {
                throw new \InvalidArgumentException('A single posting line must be either a debit or a credit, never both.');
            }

            if ($debit === 0 && $credit === 0) {
                throw new \InvalidArgumentException('A posting line must have a non-zero debit or credit amount.');
            }

            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        if ($totalDebit !== $totalCredit) {
            throw new \InvalidArgumentException(
                "Journal entry does not balance: total debits ({$totalDebit}) must equal total credits ({$totalCredit})."
            );
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use (
            $firm, $sourceType, $description, $entryDate, $postings, $sourceRefs, $postedBy, $idempotencyKey
        ) {
            return DB::transaction(function () use ($firm, $sourceType, $description, $entryDate, $postings, $sourceRefs, $postedBy, $idempotencyKey) {
                if ($idempotencyKey !== null) {
                    $existing = AccountingJournalEntry::query()
                        ->where('firm_id', $firm->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existing !== null) {
                        return $existing->fresh('postings');
                    }
                }

                $accountIds = array_unique(array_column($postings, 'chart_of_account_id'));
                $activeAccountCount = ChartOfAccount::query()
                    ->where('firm_id', $firm->id)
                    ->whereIn('id', $accountIds)
                    ->where('is_active', true)
                    ->count();

                if ($activeAccountCount !== count($accountIds)) {
                    throw new \RuntimeException('One or more posting lines reference a chart-of-accounts row that does not belong to this firm or is not active.');
                }

                $entry = AccountingJournalEntry::create([
                    'firm_id' => $firm->id,
                    'entry_date' => $entryDate,
                    'description' => $description,
                    'source_type' => $sourceType,
                    'idempotency_key' => $idempotencyKey,
                    'posted_by_firm_user_id' => $postedBy?->id,
                    'payment_id' => $sourceRefs['payment_id'] ?? null,
                    'invoice_id' => $sourceRefs['invoice_id'] ?? null,
                    'expense_id' => $sourceRefs['expense_id'] ?? null,
                    'trust_transfer_request_id' => $sourceRefs['trust_transfer_request_id'] ?? null,
                    'created_at' => now(),
                ]);

                foreach ($postings as $line) {
                    $entry->postings()->create([
                        'firm_id' => $firm->id,
                        'chart_of_account_id' => $line['chart_of_account_id'],
                        'client_id' => $line['client_id'] ?? null,
                        'matter_id' => $line['matter_id'] ?? null,
                        'debit_cents' => $line['debit_cents'] ?? 0,
                        'credit_cents' => $line['credit_cents'] ?? 0,
                        'memo' => $line['memo'] ?? null,
                    ]);
                }

                return $entry->fresh('postings');
            });
        });
    }
}
