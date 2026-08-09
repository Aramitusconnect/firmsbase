<?php

namespace App\Services;

use App\Enums\AccountingPeriodStatus;
use App\Enums\PaymentReversalType;
use App\Enums\TrustTransferRequestStatus;
use App\Models\AccountingJournalEntry;
use App\Models\AccountingPeriod;
use App\Models\Firm;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentReversal;
use App\Models\TrustTransferRequest;
use App\ValueObjects\AccountingIntegrityFinding;
use App\ValueObjects\AccountingIntegrityReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AccountingIntegrityService — Accounting Integrity Hardening Pass,
 * item 10. Read-only. Detects and reports; NEVER auto-fixes anything —
 * every finding requires a human to investigate and correct through
 * the normal, already-audited service layer (a refund through
 * OperatingPaymentRefundService, a correction through
 * AccountingJournalReversalService, etc.), never through this service
 * or its console command.
 *
 * Scoped per firm, because every check below reads FORCE-RLS-protected
 * tables and must run inside that firm's own tenant context
 * (TenantContextService::runWithFirmContext()) — see checkAllFirms()
 * for the read-only, one-firm-at-a-time sweep the console command uses.
 *
 * Checks marked "AH1-era drift" specifically exist to catch data that
 * predates this hardening pass — a Payment/TrustTransferRequest/
 * PaymentReversal created back when OperatingJournalRecorderService
 * still silently returned null could exist in an accounting-enabled
 * firm with no corresponding journal entry, and this service is the
 * one place that historical drift becomes visible.
 */
class AccountingIntegrityService
{
    public function __construct(
        private readonly AccountingEntitlementPolicyService $entitlementPolicy,
    ) {}

    public function checkFirm(Firm $firm): AccountingIntegrityReport
    {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm) {
            $findings = new Collection;

            $findings = $findings
                ->merge($this->findUnbalancedEntries($firm))
                ->merge($this->findDuplicateIdempotencyKeys($firm))
                ->merge($this->findEntriesViolatingClosedPeriods($firm))
                ->merge($this->findAllocationsExceedingPayment($firm))
                ->merge($this->findInvoiceAllocationInconsistencies($firm));

            if ($this->entitlementPolicy->isExpensesEnabledForFirm($firm)) {
                $findings = $findings
                    ->merge($this->findPaymentsMissingJournalEntries($firm))
                    ->merge($this->findAppliedTransfersMissingJournalEntries($firm))
                    ->merge($this->findReversalsMissingCompensatingEntries($firm));
            }

            return new AccountingIntegrityReport($firm->id, $findings->values(), now());
        });
    }

    /**
     * @return Collection<int, AccountingIntegrityReport>
     */
    public function checkAllFirms(): Collection
    {
        return Firm::query()->cursor()->map(fn (Firm $firm) => $this->checkFirm($firm));
    }

    /**
     * Defense in depth — AccountingJournalPostingService::post()
     * already refuses to persist an unbalanced entry, so this should
     * always be empty in practice.
     */
    private function findUnbalancedEntries(Firm $firm): Collection
    {
        $rows = DB::table('accounting_postings')
            ->where('firm_id', $firm->id)
            ->selectRaw('accounting_journal_entry_id, COALESCE(SUM(debit_cents), 0) as total_debit, COALESCE(SUM(credit_cents), 0) as total_credit')
            ->groupBy('accounting_journal_entry_id')
            ->havingRaw('COALESCE(SUM(debit_cents), 0) != COALESCE(SUM(credit_cents), 0)')
            ->get();

        return $rows->map(fn ($row) => new AccountingIntegrityFinding(
            'unbalanced_journal_entry',
            "Journal entry #{$row->accounting_journal_entry_id} does not balance: debits {$row->total_debit} != credits {$row->total_credit}.",
            'accounting_journal_entry',
            (int) $row->accounting_journal_entry_id,
        ));
    }

    /**
     * Defense in depth — the partial unique index on
     * (firm_id, idempotency_key) already prevents this at the database
     * level.
     */
    private function findDuplicateIdempotencyKeys(Firm $firm): Collection
    {
        $rows = AccountingJournalEntry::query()
            ->where('firm_id', $firm->id)
            ->whereNotNull('idempotency_key')
            ->select('idempotency_key')
            ->groupBy('idempotency_key')
            ->havingRaw('count(*) > 1')
            ->pluck('idempotency_key');

        return $rows->map(fn (string $key) => new AccountingIntegrityFinding(
            'duplicate_idempotency_key',
            "Idempotency key [{$key}] is used by more than one journal entry.",
            'accounting_journal_entry',
            0,
        ));
    }

    /**
     * A journal entry dated inside a period, whose row was CREATED
     * after that period's closed_at, is a real violation — the
     * write-time guard in AccountingJournalPostingService::post()
     * should have refused it. Entries created (and legitimately
     * posted) BEFORE the period was ever closed are never flagged.
     */
    private function findEntriesViolatingClosedPeriods(Firm $firm): Collection
    {
        $closedPeriods = AccountingPeriod::query()
            ->where('firm_id', $firm->id)
            ->where('status', AccountingPeriodStatus::Closed)
            ->get();

        $findings = new Collection;

        foreach ($closedPeriods as $period) {
            $violators = AccountingJournalEntry::query()
                ->where('firm_id', $firm->id)
                ->whereBetween('entry_date', [$period->period_start, $period->period_end])
                ->where('created_at', '>', $period->closed_at)
                ->pluck('id');

            foreach ($violators as $entryId) {
                $findings->push(new AccountingIntegrityFinding(
                    'closed_period_violation',
                    "Journal entry #{$entryId} was created after accounting period #{$period->id} was closed, but is dated inside it.",
                    'accounting_journal_entry',
                    (int) $entryId,
                ));
            }
        }

        return $findings;
    }

    /**
     * Defense in depth — PaymentApplicationService::applySplit() has
     * required exact allocation since the Accounting Integrity
     * Hardening Pass; this check exists for firms/rows predating that
     * change.
     */
    private function findAllocationsExceedingPayment(Firm $firm): Collection
    {
        $rows = PaymentAllocation::query()
            ->where('payment_allocations.firm_id', $firm->id)
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->selectRaw('payment_allocations.payment_id, payments.amount_cents as payment_amount_cents, SUM(payment_allocations.amount_cents) as allocated_cents')
            ->groupBy('payment_allocations.payment_id', 'payments.amount_cents')
            ->havingRaw('SUM(payment_allocations.amount_cents) > payments.amount_cents')
            ->get();

        return $rows->map(fn ($row) => new AccountingIntegrityFinding(
            'payment_over_allocated',
            "Payment #{$row->payment_id} has {$row->allocated_cents} cents allocated against an amount of only {$row->payment_amount_cents} cents.",
            'payment',
            (int) $row->payment_id,
        ));
    }

    /**
     * The sum of an invoice's split-sourced allocations can never
     * exceed what the invoice actually records as paid — allocations
     * are always a subset of amount_paid_cents, never a parallel
     * figure.
     */
    private function findInvoiceAllocationInconsistencies(Firm $firm): Collection
    {
        $rows = PaymentAllocation::query()
            ->where('payment_allocations.firm_id', $firm->id)
            ->whereNotNull('invoice_id')
            ->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
            ->selectRaw('payment_allocations.invoice_id, invoices.amount_paid_cents, SUM(payment_allocations.amount_cents) as allocated_cents')
            ->groupBy('payment_allocations.invoice_id', 'invoices.amount_paid_cents')
            ->havingRaw('SUM(payment_allocations.amount_cents) > invoices.amount_paid_cents')
            ->get();

        return $rows->map(fn ($row) => new AccountingIntegrityFinding(
            'invoice_allocation_exceeds_amount_paid',
            "Invoice #{$row->invoice_id} has {$row->allocated_cents} cents allocated against it but only records {$row->amount_paid_cents} cents paid.",
            'invoice',
            (int) $row->invoice_id,
        ));
    }

    /**
     * AH1-era drift check: an accepted, single-target operating
     * payment that should have posted a fee-earned entry
     * (OperatingJournalRecorderService::recordInvoicePaymentApplied()/
     * recordInstallmentPaymentApplied()) but has none on record.
     */
    private function findPaymentsMissingJournalEntries(Firm $firm): Collection
    {
        $entriesWithPaymentId = AccountingJournalEntry::query()
            ->where('firm_id', $firm->id)
            ->whereNotNull('payment_id')
            ->pluck('payment_id');

        $rows = Payment::query()
            ->where('firm_id', $firm->id)
            ->where(fn ($q) => $q->whereNotNull('invoice_id')->orWhereNotNull('payment_plan_installment_id'))
            ->whereNotIn('id', $entriesWithPaymentId)
            ->get()
            ->filter(fn (Payment $payment) => $payment->isAcceptedOperatingPayment());

        return $rows->map(fn (Payment $payment) => new AccountingIntegrityFinding(
            'payment_missing_journal_entry',
            "Payment #{$payment->id} is an accepted operating payment applied to a target, but has no accounting journal entry.",
            'payment',
            (int) $payment->id,
        ));
    }

    /**
     * AH1-era drift check: an Applied trust-to-operating transfer that
     * should have posted a fee-earned entry
     * (OperatingJournalRecorderService::recordTrustToOperatingTransfer())
     * but has none on record.
     */
    private function findAppliedTransfersMissingJournalEntries(Firm $firm): Collection
    {
        $entriesWithTransferId = AccountingJournalEntry::query()
            ->where('firm_id', $firm->id)
            ->whereNotNull('trust_transfer_request_id')
            ->pluck('trust_transfer_request_id');

        $rows = TrustTransferRequest::query()
            ->where('firm_id', $firm->id)
            ->where('status', TrustTransferRequestStatus::Applied)
            ->whereNotIn('id', $entriesWithTransferId)
            ->get();

        return $rows->map(fn (TrustTransferRequest $request) => new AccountingIntegrityFinding(
            'trust_transfer_missing_journal_entry',
            "TrustTransferRequest #{$request->id} is Applied, but has no accounting journal entry on the operating side.",
            'trust_transfer_request',
            (int) $request->id,
        ));
    }

    /**
     * AH1-era drift check: a refund/chargeback
     * (OperatingPaymentRefundService/OperatingChargebackService) that
     * should have posted a compensating cash-out entry
     * (OperatingJournalRecorderService::recordCashOut(), whose
     * idempotency key exactly matches "{refund|chargeback}:
     * payment_reversal:{id}") but has none on record.
     */
    private function findReversalsMissingCompensatingEntries(Firm $firm): Collection
    {
        $existingKeys = AccountingJournalEntry::query()
            ->where('firm_id', $firm->id)
            ->whereNotNull('idempotency_key')
            ->pluck('idempotency_key')
            ->flip();

        $rows = PaymentReversal::query()->where('firm_id', $firm->id)->get();

        return $rows->filter(function (PaymentReversal $reversal) use ($existingKeys) {
            $prefix = $reversal->reversal_type === PaymentReversalType::Chargeback ? 'chargeback' : 'refund';
            $expectedKey = "{$prefix}:payment_reversal:{$reversal->id}";

            return ! $existingKeys->has($expectedKey);
        })->map(fn (PaymentReversal $reversal) => new AccountingIntegrityFinding(
            'reversal_missing_compensating_entry',
            "PaymentReversal #{$reversal->id} ({$reversal->reversal_type->value}) has no compensating accounting journal entry.",
            'payment_reversal',
            (int) $reversal->id,
        ));
    }
}
