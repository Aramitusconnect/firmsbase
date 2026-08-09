<?php

namespace App\Services;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountType;
use App\Models\AccountingJournalEntry;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentPlanInstallment;
use App\Models\TrustTransferRequest;

/**
 * OperatingJournalRecorderService — Phase D of the legal accounting
 * foundation: the translator between a real business event (a payment
 * got applied, an expense got approved, a trust transfer landed) and
 * the double-entry postings AccountingJournalPostingService::post()
 * needs. AccountingJournalPostingService itself stays domain-agnostic
 * (per its own docblock, it never queries Invoice/Payment/Expense);
 * this is the one place that DOES know how those domain events map to
 * debit/credit lines, so that mapping doesn't get duplicated at every
 * call site (ManualPaymentService, TrustTransferRequestService,
 * ExpenseApprovalService, and the new refund/write-off/chargeback
 * services from Phase G).
 *
 * Revenue-recognition model (an explicit, documented design choice —
 * not an oversight): fees are recognized as earned Revenue at the
 * moment cash is actually received against a billed invoice
 * (InvoicePaymentApplied / TrustToOperatingTransfer), not at invoice
 * issuance. This is the same moment PaymentClassificationService and
 * PaymentApplicationService already treat as authoritative ("payment
 * accepted"), so it needs no new authorization gate. It also directly
 * answers Phase C's "earned vs unearned" requirement: money sitting in
 * a client's trust ledger is never on these operating books at all
 * (TrustLedgerEntry/TrustBalance remain the sole source of truth for
 * that — see AccountingEarnedFeeService for the read-only "unearned"
 * query), and it only becomes firm revenue the instant
 * TrustTransferRequestService::apply() converts it into a real
 * Payment against an approved invoice. A direct (non-trust) invoice
 * payment recognizes revenue the same way, at the same moment.
 *
 * Every method here is deliberately BEST-EFFORT / OPT-IN, never a hard
 * requirement: if the firm has not yet built out an active Chart of
 * Accounts covering the account types a posting needs (ChartOfAccountsService
 * seeds nothing by default — every firm starts with zero rows), the
 * method returns null and the underlying business operation (payment
 * application, expense approval, trust transfer) still succeeds
 * unaffected. Forcing a hard dependency here would mean a firm that
 * has not opted into double-entry bookkeeping could no longer record
 * payments or approve expenses at all, which is not what "the journal
 * represents their accounting consequence" (never replaces or gates
 * the underlying business record) is asking for.
 */
class OperatingJournalRecorderService
{
    public function __construct(
        private readonly ChartOfAccountsService $chartOfAccounts,
        private readonly AccountingJournalPostingService $posting,
    ) {}

    public function recordInvoicePaymentApplied(Firm $firm, Payment $payment, Invoice $invoice): ?AccountingJournalEntry
    {
        return $this->recordFeeEarned(
            $firm,
            $payment,
            AccountingJournalSourceType::InvoicePaymentApplied,
            "invoice_payment_applied:payment:{$payment->id}",
            "Fees earned — invoice #{$invoice->id} payment applied",
            invoiceId: $invoice->id,
        );
    }

    public function recordTrustToOperatingTransfer(Firm $firm, Payment $payment, Invoice $invoice, TrustTransferRequest $request): ?AccountingJournalEntry
    {
        return $this->recordFeeEarned(
            $firm,
            $payment,
            AccountingJournalSourceType::TrustToOperatingTransfer,
            "trust_to_operating_transfer:{$request->id}",
            "Fees earned — trust transfer #{$request->id} applied to invoice #{$invoice->id}",
            invoiceId: $invoice->id,
            trustTransferRequestId: $request->id,
        );
    }

    public function recordInstallmentPaymentApplied(Firm $firm, Payment $payment, PaymentPlanInstallment $installment): ?AccountingJournalEntry
    {
        $invoiceId = $installment->paymentPlan?->invoice_id;

        return $this->recordFeeEarned(
            $firm,
            $payment,
            AccountingJournalSourceType::InvoicePaymentApplied,
            "invoice_payment_applied:payment:{$payment->id}",
            "Fees earned — payment plan installment #{$installment->id} applied",
            invoiceId: $invoiceId,
        );
    }

    public function recordExpensePaid(Firm $firm, Expense $expense): ?AccountingJournalEntry
    {
        $cash = $this->chartOfAccounts->resolveActiveAccountByType($firm, ChartOfAccountType::Asset);
        $expenseAccount = $expense->category?->chartOfAccount
            ?? $this->chartOfAccounts->resolveActiveAccountByType($firm, ChartOfAccountType::Expense);

        if ($cash === null || $expenseAccount === null) {
            return null;
        }

        return $this->posting->post(
            $firm,
            AccountingJournalSourceType::ExpensePaid,
            "Expense paid — {$expense->vendor_name}",
            $expense->expense_date,
            [
                ['chart_of_account_id' => $expenseAccount->id, 'debit_cents' => $expense->amount_cents, 'credit_cents' => 0, 'matter_id' => $expense->matter_id],
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 0, 'credit_cents' => $expense->amount_cents],
            ],
            ['expense_id' => $expense->id],
            idempotencyKey: "expense_paid:{$expense->id}",
        );
    }

    /**
     * Reverses previously-recognized revenue for cash actually leaving
     * the firm (a refund) or being clawed back by a processor (a
     * chargeback) — the only two source types allowed here. Called by
     * Phase G's OperatingPaymentRefundService / OperatingChargebackService,
     * never directly.
     */
    public function recordCashOut(
        Firm $firm,
        AccountingJournalSourceType $sourceType,
        string $description,
        int $amountCents,
        string $idempotencyKey,
        ?int $paymentId = null,
        ?int $invoiceId = null,
    ): ?AccountingJournalEntry {
        if (! in_array($sourceType, [AccountingJournalSourceType::Refund, AccountingJournalSourceType::Chargeback], true)) {
            throw new \InvalidArgumentException('recordCashOut() only supports Refund and Chargeback source types.');
        }

        $cash = $this->chartOfAccounts->resolveActiveAccountByType($firm, ChartOfAccountType::Asset);
        $revenue = $this->chartOfAccounts->resolveActiveAccountByType($firm, ChartOfAccountType::Revenue);

        if ($cash === null || $revenue === null) {
            return null;
        }

        return $this->posting->post(
            $firm,
            $sourceType,
            $description,
            now(),
            [
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => $amountCents, 'credit_cents' => 0],
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 0, 'credit_cents' => $amountCents],
            ],
            ['payment_id' => $paymentId, 'invoice_id' => $invoiceId],
            idempotencyKey: $idempotencyKey,
        );
    }

    private function recordFeeEarned(
        Firm $firm,
        Payment $payment,
        AccountingJournalSourceType $sourceType,
        string $idempotencyKey,
        string $description,
        ?int $invoiceId = null,
        ?int $trustTransferRequestId = null,
    ): ?AccountingJournalEntry {
        $cash = $this->chartOfAccounts->resolveActiveAccountByType($firm, ChartOfAccountType::Asset);
        $revenue = $this->chartOfAccounts->resolveActiveAccountByType($firm, ChartOfAccountType::Revenue);

        if ($cash === null || $revenue === null) {
            return null;
        }

        return $this->posting->post(
            $firm,
            $sourceType,
            $description,
            now(),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => $payment->amount_cents, 'credit_cents' => 0, 'client_id' => $payment->client_id, 'matter_id' => $payment->matter_id],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => $payment->amount_cents, 'client_id' => $payment->client_id, 'matter_id' => $payment->matter_id],
            ],
            ['payment_id' => $payment->id, 'invoice_id' => $invoiceId, 'trust_transfer_request_id' => $trustTransferRequestId],
            idempotencyKey: $idempotencyKey,
        );
    }
}
