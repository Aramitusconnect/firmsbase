<?php

namespace App\Services;

use App\Enums\AccountingPeriodStatus;
use App\Enums\ChartOfAccountPurpose;
use App\Enums\InvoiceLineType;
use App\Enums\PaymentRequestEventType;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentReversalType;
use App\Enums\PendingPaymentAllocationStatus;
use App\Enums\TrustTransferRequestStatus;
use App\Models\AccountingJournalEntry;
use App\Models\AccountingPeriod;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRequest;
use App\Models\PaymentReversal;
use App\Models\PendingPaymentAllocation;
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
                    ->merge($this->findReversalsMissingCompensatingEntries($firm))
                    ->merge($this->findPaymentRequestPaymentsMissingJournalEntries($firm))
                    ->merge($this->findMixedInvoicesFullyPaidWithNoCostAllocation($firm))
                    ->merge($this->findPaymentAllocationTotalsNotMatchingJournalRevenuePostings($firm));
            }

            $findings = $findings
                ->merge($this->findPaidPaymentRequestsMissingPayment($firm))
                ->merge($this->findDuplicateProviderTransactionIds($firm))
                ->merge($this->findPaymentRequestAmountMismatches($firm))
                ->merge($this->findPaymentRequestTargetMismatches($firm))
                ->merge($this->findUnpostedTrustDepositPaymentRequests($firm))
                ->merge($this->findUnresolvedPendingPaymentAllocations($firm));

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

    /**
     * Payment Link / QR Routing phase, master prompt item 13: a
     * PaymentRequest whose own status says money was routed all the way
     * to Paid, but which carries no payment_id at all — this should be
     * structurally impossible given
     * PaymentRequestCheckoutService::routeOperatingPayment() always sets
     * both together in the same update() call, but this check exists as
     * defense in depth against any future code path that might set
     * status without the payment consequence.
     */
    private function findPaidPaymentRequestsMissingPayment(Firm $firm): Collection
    {
        $rows = PaymentRequest::query()
            ->where('firm_id', $firm->id)
            ->where('status', PaymentRequestStatus::Paid)
            ->whereNull('payment_id')
            ->get();

        return $rows->map(fn (PaymentRequest $paymentRequest) => new AccountingIntegrityFinding(
            'payment_request_paid_without_payment',
            "PaymentRequest #{$paymentRequest->id} is Paid but has no payment_id — a completed entry-channel request must always carry its own accounting consequence.",
            'payment_request',
            (int) $paymentRequest->id,
        ));
    }

    /**
     * A Paid PaymentRequest's underlying Payment must itself have a
     * journal entry — this is the payment-request-specific mirror of
     * findPaymentsMissingJournalEntries() above, scoped to payments
     * that arrived via the entry channel rather than every operating
     * payment in the firm, so it surfaces even when the firm's general
     * expenses-gated sweep above is skipped.
     *
     * Scoped to Payments with an invoice/installment target, exactly
     * like findPaymentsMissingJournalEntries() above — a standalone
     * PaymentRequest with no target (ManualPaymentService::submit()'s
     * own "if ($installment) ... elseif ($invoice) ..." branch is
     * skipped entirely otherwise) never posts a journal entry by
     * design, so it must never be flagged here as drift.
     */
    private function findPaymentRequestPaymentsMissingJournalEntries(Firm $firm): Collection
    {
        $entriesWithPaymentId = AccountingJournalEntry::query()
            ->where('firm_id', $firm->id)
            ->whereNotNull('payment_id')
            ->pluck('payment_id');

        $rows = PaymentRequest::query()
            ->where('payment_requests.firm_id', $firm->id)
            ->where('payment_requests.status', PaymentRequestStatus::Paid)
            ->whereNotNull('payment_requests.payment_id')
            ->whereNotIn('payment_requests.payment_id', $entriesWithPaymentId)
            ->join('payments', 'payments.id', '=', 'payment_requests.payment_id')
            ->where(fn ($q) => $q->whereNotNull('payments.invoice_id')->orWhereNotNull('payments.payment_plan_installment_id'))
            ->select('payment_requests.*')
            ->get();

        return $rows->map(fn (PaymentRequest $paymentRequest) => new AccountingIntegrityFinding(
            'payment_request_payment_missing_journal_entry',
            "PaymentRequest #{$paymentRequest->id} is Paid via Payment #{$paymentRequest->payment_id}, but that payment has no accounting journal entry.",
            'payment_request',
            (int) $paymentRequest->id,
        ));
    }

    /**
     * Defense in depth — payment_requests carries a
     * unique(['firm_id','provider_transaction_id']) constraint already,
     * so this should always be empty in practice; surfaced here anyway
     * per the master prompt's own explicit reconciliation requirement
     * ("duplicate provider transaction").
     */
    private function findDuplicateProviderTransactionIds(Firm $firm): Collection
    {
        $rows = PaymentRequest::query()
            ->where('firm_id', $firm->id)
            ->whereNotNull('provider_transaction_id')
            ->select('provider_transaction_id')
            ->groupBy('provider_transaction_id')
            ->havingRaw('count(*) > 1')
            ->pluck('provider_transaction_id');

        return $rows->map(fn (string $providerTransactionId) => new AccountingIntegrityFinding(
            'payment_request_duplicate_provider_transaction',
            "Provider transaction [{$providerTransactionId}] is attributed to more than one payment request.",
            'payment_request',
            0,
        ));
    }

    /**
     * "Wrong amount": the amount a PaymentRequest recorded as paid must
     * exactly match the amount its own resulting Payment records —
     * these are written together in the same
     * PaymentRequestCheckoutService::routeOperatingPayment() update(),
     * so any mismatch indicates drift, not a legitimate partial state.
     */
    private function findPaymentRequestAmountMismatches(Firm $firm): Collection
    {
        $rows = PaymentRequest::query()
            ->where('payment_requests.firm_id', $firm->id)
            ->whereNotNull('payment_requests.payment_id')
            ->join('payments', 'payments.id', '=', 'payment_requests.payment_id')
            ->whereColumn('payment_requests.paid_amount_cents', '!=', 'payments.amount_cents')
            ->select('payment_requests.id', 'payment_requests.paid_amount_cents', 'payments.amount_cents as payment_amount_cents')
            ->get();

        return $rows->map(fn ($row) => new AccountingIntegrityFinding(
            'payment_request_amount_mismatch',
            "PaymentRequest #{$row->id} recorded paid_amount_cents {$row->paid_amount_cents}, but its Payment records amount_cents {$row->payment_amount_cents}.",
            'payment_request',
            (int) $row->id,
        ));
    }

    /**
     * "Wrong target": the invoice/installment a PaymentRequest was
     * created against must match the invoice/installment its own
     * resulting Payment was applied to —
     * PaymentRequestService::assertPurposeTargetConsistency() enforces
     * this at creation time, but this check catches any drift after
     * the fact (e.g. a Payment later re-applied by an unrelated code
     * path).
     */
    private function findPaymentRequestTargetMismatches(Firm $firm): Collection
    {
        $rows = PaymentRequest::query()
            ->where('payment_requests.firm_id', $firm->id)
            ->whereNotNull('payment_requests.payment_id')
            ->join('payments', 'payments.id', '=', 'payment_requests.payment_id')
            ->where(function ($query) {
                $query
                    ->whereColumn('payment_requests.invoice_id', '!=', 'payments.invoice_id')
                    ->orWhereColumn('payment_requests.payment_plan_installment_id', '!=', 'payments.payment_plan_installment_id')
                    ->orWhere(function ($q) {
                        $q->whereNotNull('payment_requests.invoice_id')->whereNull('payments.invoice_id');
                    })
                    ->orWhere(function ($q) {
                        $q->whereNotNull('payment_requests.payment_plan_installment_id')->whereNull('payments.payment_plan_installment_id');
                    });
            })
            ->select('payment_requests.id')
            ->get();

        return $rows->map(fn ($row) => new AccountingIntegrityFinding(
            'payment_request_target_mismatch',
            "PaymentRequest #{$row->id}'s invoice/installment target does not match the invoice/installment its resulting Payment was applied to.",
            'payment_request',
            (int) $row->id,
        ));
    }

    /**
     * "Trust request not posted": a Trust-deposit-purpose PaymentRequest
     * whose provider already confirmed the money (provider_transaction_id
     * set) must always have filed a TrustDepositRequested event —
     * PaymentRequestCheckoutService::routeTrustDeposit() records this on
     * every success path; its absence means a confirmed payment never
     * reached TrustDepositService::requestDeposit() at all (e.g. the
     * client had no trust ledger — that specific case ALSO produces a
     * Failed event with a distinct, human-readable note, but still
     * surfaces here since the underlying money was never filed toward
     * an approvable trust deposit).
     */
    private function findUnpostedTrustDepositPaymentRequests(Firm $firm): Collection
    {
        $filed = DB::table('payment_request_events')
            ->where('firm_id', $firm->id)
            ->where('event_type', PaymentRequestEventType::TrustDepositRequested->value)
            ->pluck('payment_request_id');

        $rows = PaymentRequest::query()
            ->where('firm_id', $firm->id)
            ->where('purpose', PaymentRequestPurpose::TrustDeposit)
            ->whereNotNull('provider_transaction_id')
            ->whereNotIn('id', $filed)
            ->get();

        return $rows->map(fn (PaymentRequest $paymentRequest) => new AccountingIntegrityFinding(
            'payment_request_trust_deposit_not_posted',
            "PaymentRequest #{$paymentRequest->id} is a confirmed Trust deposit whose money was never filed as a TrustDepositService deposit request.",
            'payment_request',
            (int) $paymentRequest->id,
        ));
    }

    /**
     * Mixed-Invoice Revenue Allocation pass, item 10 — a fully paid
     * mixed invoice (has at least one ReimbursableExpense line, AND
     * amount_paid_cents >= total_cents) must have SOME cost_reimbursement_
     * revenue-tagged PaymentAllocation row on record. Its absence means
     * either an AH1-era payment predating this pass's split logic, or a
     * genuine drift where the cost portion was silently classified as
     * legal-fee revenue — exactly the misclassification this whole
     * phase exists to prevent.
     */
    private function findMixedInvoicesFullyPaidWithNoCostAllocation(Firm $firm): Collection
    {
        $invoiceIdsWithCostAllocation = PaymentAllocation::query()
            ->where('firm_id', $firm->id)
            ->where('revenue_purpose', ChartOfAccountPurpose::CostReimbursementRevenue->value)
            ->whereNotNull('invoice_id')
            ->pluck('invoice_id');

        $invoiceIdsWithCostLines = DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->where('invoices.firm_id', $firm->id)
            ->where('invoice_lines.line_type', InvoiceLineType::ReimbursableExpense->value)
            ->pluck('invoice_lines.invoice_id')
            ->unique();

        $rows = Invoice::query()
            ->where('firm_id', $firm->id)
            ->whereIn('id', $invoiceIdsWithCostLines)
            ->whereColumn('amount_paid_cents', '>=', 'total_cents')
            ->where('total_cents', '>', 0)
            ->whereNotIn('id', $invoiceIdsWithCostAllocation)
            ->get();

        return $rows->map(fn (Invoice $invoice) => new AccountingIntegrityFinding(
            'mixed_invoice_fully_paid_with_no_cost_allocation',
            "Invoice #{$invoice->id} has reimbursable-expense lines and is fully paid, but no cost-reimbursement-revenue allocation is on record — its payment(s) may have been entirely misclassified as legal-fee revenue.",
            'invoice',
            (int) $invoice->id,
        ));
    }

    /**
     * Mixed-Invoice Revenue Allocation pass, item 10 — for every
     * invoice with revenue_purpose-tagged PaymentAllocation rows, the
     * sum per purpose must reconcile to the sum of that invoice's own
     * journal postings credited to the matching chart-of-account
     * purpose. A mismatch means either a fee allocation posted to the
     * cost account, a cost allocation posted to the fee account, or a
     * PaymentAllocation total that simply doesn't match what was
     * actually posted.
     */
    private function findPaymentAllocationTotalsNotMatchingJournalRevenuePostings(Firm $firm): Collection
    {
        $allocationTotals = PaymentAllocation::query()
            ->where('firm_id', $firm->id)
            ->whereNotNull('invoice_id')
            ->whereNotNull('revenue_purpose')
            ->selectRaw('invoice_id, revenue_purpose, SUM(amount_cents) as total_cents')
            ->groupBy('invoice_id', 'revenue_purpose')
            ->get();

        $findings = new Collection;

        foreach ($allocationTotals as $row) {
            $journalTotal = (int) DB::table('accounting_postings')
                ->join('accounting_journal_entries', 'accounting_journal_entries.id', '=', 'accounting_postings.accounting_journal_entry_id')
                ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'accounting_postings.chart_of_account_id')
                ->where('accounting_journal_entries.firm_id', $firm->id)
                ->where('accounting_journal_entries.invoice_id', $row->invoice_id)
                ->where('chart_of_accounts.purpose', $row->revenue_purpose)
                ->sum('accounting_postings.credit_cents');

            if ($journalTotal !== (int) $row->total_cents) {
                $findings->push(new AccountingIntegrityFinding(
                    'payment_allocation_total_mismatches_journal_posting',
                    "Invoice #{$row->invoice_id}'s {$row->revenue_purpose} PaymentAllocation total ({$row->total_cents}) does not match its journal postings to that account ({$journalTotal}).",
                    'invoice',
                    (int) $row->invoice_id,
                ));
            }
        }

        return $findings;
    }

    /**
     * Mixed-Invoice Revenue Allocation pass, item 10 — surfaces every
     * still-open PendingPaymentAllocation so it doesn't silently age
     * out of view. Not itself a "wrong" state (an unresolved allocation
     * is an expected, governed intermediate state — see
     * PendingPaymentAllocation's own docblock), but explicitly listed
     * per the master prompt's own item 11 ("finalized payment with
     * unresolved allocation"): read-only visibility into the review
     * backlog, never auto-resolved here.
     */
    private function findUnresolvedPendingPaymentAllocations(Firm $firm): Collection
    {
        $rows = PendingPaymentAllocation::query()
            ->where('firm_id', $firm->id)
            ->where('status', PendingPaymentAllocationStatus::Pending)
            ->get();

        return $rows->map(fn (PendingPaymentAllocation $pending) => new AccountingIntegrityFinding(
            'payment_pending_allocation_unresolved',
            "Payment #{$pending->payment_id} has {$pending->amount_cents} cents awaiting a fee/cost allocation decision on invoice #{$pending->invoice_id} ({$pending->reason}).",
            'pending_payment_allocation',
            (int) $pending->id,
        ));
    }
}
