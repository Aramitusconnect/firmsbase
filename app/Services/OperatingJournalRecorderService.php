<?php

namespace App\Services;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountPurpose;
use App\Enums\InvoiceLineType;
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
 * ExpenseApprovalService, and the refund/write-off/chargeback services
 * from Phase G).
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
 * ============================================================
 * ACCOUNTING INTEGRITY HARDENING PASS, item 1 — atomic post-or-block
 * policy (replaces the earlier "best-effort / opt-in, silently return
 * null" posture this class used to document here).
 * ============================================================
 *
 * Every method below now does exactly one of two things — never a
 * silent third:
 *
 *   1. NOT APPLICABLE — the firm has never enabled the accounting
 *      module at all (AccountingEntitlementPolicyService::
 *      isExpensesEnabledForFirm() === false, the same entitlement
 *      ChartOfAccountsService::create() already requires). No
 *      accounting consequence is expected for such a firm — this is
 *      the SAME entitlement gate every other Phase-12 service already
 *      obeys, not a new concept — so returning null here is a
 *      documented "out of scope," never a silently-dropped event.
 *      AccountingIntegrityService (the read-only consistency checker)
 *      skips these firms entirely for the same reason.
 *
 *   2. ATOMIC SUCCESS OR ATOMIC FAILURE — the firm HAS enabled
 *      accounting, so a posting is genuinely expected. Every call site
 *      below now resolves its required chart_of_accounts purpose via
 *      ChartOfAccountsService::requireByPurpose(), which THROWS
 *      AccountingSetupIncompleteException instead of returning null
 *      when the required account is missing. Every real caller of
 *      these methods (ManualPaymentService::submit(),
 *      TrustTransferRequestService::apply(),
 *      ExpenseApprovalService::recordDecision(),
 *      OperatingPaymentRefundService::refund(),
 *      OperatingChargebackService::report()) already performs its
 *      entire business mutation AND its journal-recording call inside
 *      ONE shared TenantContextService::runWithFirmContext() closure,
 *      which itself wraps in a real DB::transaction() — audited call
 *      site by call site as part of this hardening pass, not assumed.
 *      Throwing here therefore rolls back the WHOLE business
 *      transaction (the Payment row, the ExpenseApproval, the
 *      TrustLedgerEntry withdrawal, everything) exactly as cleanly as
 *      if the business mutation itself had failed. There is never a
 *      state where the business event committed but no accounting
 *      consequence exists for it — the two either land together or
 *      neither lands at all. See AccountingSetupIncompleteException's
 *      own docblock.
 */
class OperatingJournalRecorderService
{
    public function __construct(
        private readonly ChartOfAccountsService $chartOfAccounts,
        private readonly AccountingJournalPostingService $posting,
        private readonly AccountingEntitlementPolicyService $entitlementPolicy,
    ) {}

    /**
     * Payment-Channel Safety Hardening pass, item 4/5 — a mixed invoice
     * (legal-fee lines + ReimbursableExpense lines) must not have its
     * entire payment posted as undifferentiated LegalFeeRevenue.
     *
     * Splitting is only ever attempted in the ONE case that is
     * genuinely unambiguous: this Payment is the invoice's first and
     * only payment (amount_paid_cents was 0 before it — derivable as
     * $invoice->amount_paid_cents - $payment->amount_cents, since
     * PaymentApplicationService::applyToInvoice() always adds this
     * payment's amount on top of whatever was there before), AND it
     * pays the invoice off in full. In that exact case every line's
     * full amount is necessarily funded by this one payment, so the
     * fee/cost split is just the lines' own totals — no allocation
     * policy is needed or invented.
     *
     * A mixed invoice funded by more than one payment (this payment
     * has predecessors, or leaves a remainder) is a GENUINELY
     * ambiguous case this codebase has never defined a policy for —
     * pro-rata, cost-first, and fee-first are all defensible and none
     * is chosen here; see resolveFeeCostSplitForFullyPaidInvoice()'s
     * own docblock and the phase's own final report ("remaining
     * blockers") for the exact decision still needed. That case falls
     * back to today's single-leg LegalFeeRevenue posting unchanged —
     * deliberately reported, not silently misclassified as "handled."
     */
    public function recordInvoicePaymentApplied(Firm $firm, Payment $payment, Invoice $invoice): ?AccountingJournalEntry
    {
        $split = $this->resolveFeeCostSplitForFullyPaidInvoice($invoice, $payment);

        if ($split !== null) {
            return $this->recordFeeEarnedWithCostSplit(
                $firm,
                $payment,
                AccountingJournalSourceType::InvoicePaymentApplied,
                "invoice_payment_applied:payment:{$payment->id}",
                "Fees earned — invoice #{$invoice->id} payment applied",
                $split['fee_cents'],
                $split['cost_cents'],
                invoiceId: $invoice->id,
            );
        }

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
        if (! $this->isAccountingApplicable($firm)) {
            return null;
        }

        $cash = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::OperatingCash);
        $expenseAccount = $expense->category?->chartOfAccount
            ?? $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::GeneralOperatingExpense);

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

        if (! $this->isAccountingApplicable($firm)) {
            return null;
        }

        $cash = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::OperatingCash);
        $revenue = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::LegalFeeRevenue);

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
        if (! $this->isAccountingApplicable($firm)) {
            return null;
        }

        $cash = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::OperatingCash);
        $revenue = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::LegalFeeRevenue);

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

    /**
     * @return array{fee_cents:int, cost_cents:int}|null null when no
     *                                                   split should be attempted (fee-only invoice, ambiguous
     *                                                   partial-payment case, or a lines/total mismatch that would
     *                                                   otherwise break the double-entry balance).
     */
    private function resolveFeeCostSplitForFullyPaidInvoice(Invoice $invoice, Payment $payment): ?array
    {
        $priorPaidCents = $invoice->amount_paid_cents - $payment->amount_cents;

        if ($priorPaidCents !== 0) {
            // This invoice already had a prior payment (or payments)
            // applied — which category THIS payment's dollars fund is
            // genuinely ambiguous. Not attempted; see this method's
            // own docblock above.
            return null;
        }

        if ($invoice->amount_paid_cents < $invoice->total_cents) {
            // This single payment does not pay the invoice off in
            // full — same ambiguity as above.
            return null;
        }

        $lines = $invoice->lines;
        $costCents = (int) $lines->where('line_type', InvoiceLineType::ReimbursableExpense)->sum('amount_cents');
        $feeCents = (int) $lines->sum('amount_cents') - $costCents;

        if ($costCents <= 0) {
            // Fee-only invoice — the existing single-leg posting is
            // already correct.
            return null;
        }

        if ($feeCents < 0 || $feeCents + $costCents !== $payment->amount_cents) {
            // Defensive: lines don't reconcile to the payment amount
            // (e.g. an Adjustment line or another drift source) —
            // never post a split that wouldn't balance. Falls back to
            // the safe, existing single-leg posting.
            return null;
        }

        return ['fee_cents' => $feeCents, 'cost_cents' => $costCents];
    }

    /**
     * The split-posting sibling of recordFeeEarned() — same cash debit,
     * but the revenue credit is divided across LegalFeeRevenue and
     * CostReimbursementRevenue rather than posted as one undifferentiated
     * line. Only ever called from the one unambiguous case
     * resolveFeeCostSplitForFullyPaidInvoice() identifies.
     */
    private function recordFeeEarnedWithCostSplit(
        Firm $firm,
        Payment $payment,
        AccountingJournalSourceType $sourceType,
        string $idempotencyKey,
        string $description,
        int $feeCents,
        int $costCents,
        ?int $invoiceId = null,
    ): ?AccountingJournalEntry {
        if (! $this->isAccountingApplicable($firm)) {
            return null;
        }

        $cash = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::OperatingCash);

        $postings = [
            ['chart_of_account_id' => $cash->id, 'debit_cents' => $payment->amount_cents, 'credit_cents' => 0, 'client_id' => $payment->client_id, 'matter_id' => $payment->matter_id],
        ];

        if ($feeCents > 0) {
            $feeRevenue = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::LegalFeeRevenue);
            $postings[] = ['chart_of_account_id' => $feeRevenue->id, 'debit_cents' => 0, 'credit_cents' => $feeCents, 'client_id' => $payment->client_id, 'matter_id' => $payment->matter_id];
        }

        if ($costCents > 0) {
            $costRevenue = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::CostReimbursementRevenue);
            $postings[] = ['chart_of_account_id' => $costRevenue->id, 'debit_cents' => 0, 'credit_cents' => $costCents, 'client_id' => $payment->client_id, 'matter_id' => $payment->matter_id];
        }

        return $this->posting->post(
            $firm,
            $sourceType,
            $description,
            now(),
            $postings,
            ['payment_id' => $payment->id, 'invoice_id' => $invoiceId],
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * The ONLY place "does accounting apply to this firm at all" is
     * decided — every public method above calls this before resolving
     * any purpose, so a firm that has never enabled the accounting
     * module keeps recording payments/expenses/transfers exactly as it
     * always could, with no accounting consequence expected or missed.
     */
    private function isAccountingApplicable(Firm $firm): bool
    {
        return $this->entitlementPolicy->isExpensesEnabledForFirm($firm);
    }
}
