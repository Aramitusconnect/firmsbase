<?php

namespace App\Services;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountPurpose;
use App\Models\AccountingJournalEntry;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentPlanInstallment;
use App\Models\PendingPaymentAllocation;
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
     * Mixed-Invoice Revenue Allocation pass — the caller
     * (ManualPaymentService::submit()) always supplies the exact
     * feeCents/costCents split, already resolved (never ambiguous) via
     * PaymentApplicationService::resolveInvoiceRevenueAllocation(). This
     * method only posts what it's told; it never re-derives or
     * second-guesses the split itself, keeping "what does this invoice
     * event mean" (PaymentApplicationService) and "how is it posted"
     * (this class) cleanly separated.
     */
    public function recordInvoicePaymentApplied(Firm $firm, Payment $payment, Invoice $invoice, int $feeCents, int $costCents): ?AccountingJournalEntry
    {
        return $this->recordFeeEarned(
            $firm,
            $payment,
            AccountingJournalSourceType::InvoicePaymentApplied,
            "invoice_payment_applied:payment:{$payment->id}",
            "Fees earned — invoice #{$invoice->id} payment applied",
            $feeCents,
            $costCents,
            invoiceId: $invoice->id,
        );
    }

    /**
     * A trust-funded transfer applied to an invoice is deliberately
     * OUT OF SCOPE for the fee/cost split (a genuinely separate,
     * pre-existing flow — TrustTransferRequestService::apply() — that
     * this phase's own test matrix does not cover): always posts
     * 100% to LegalFeeRevenue, unchanged from before this phase.
     * Reported explicitly as an out-of-scope combination, not silently
     * handled.
     */
    public function recordTrustToOperatingTransfer(Firm $firm, Payment $payment, Invoice $invoice, TrustTransferRequest $request): ?AccountingJournalEntry
    {
        return $this->recordFeeEarned(
            $firm,
            $payment,
            AccountingJournalSourceType::TrustToOperatingTransfer,
            "trust_to_operating_transfer:{$request->id}",
            "Fees earned — trust transfer #{$request->id} applied to invoice #{$invoice->id}",
            $payment->amount_cents,
            0,
            invoiceId: $invoice->id,
            trustTransferRequestId: $request->id,
        );
    }

    public function recordInstallmentPaymentApplied(Firm $firm, Payment $payment, PaymentPlanInstallment $installment, int $feeCents, int $costCents): ?AccountingJournalEntry
    {
        $invoiceId = $installment->paymentPlan?->invoice_id;

        return $this->recordFeeEarned(
            $firm,
            $payment,
            AccountingJournalSourceType::InvoicePaymentApplied,
            "invoice_payment_applied:payment:{$payment->id}",
            "Fees earned — payment plan installment #{$installment->id} applied",
            $feeCents,
            $costCents,
            invoiceId: $invoiceId,
        );
    }

    /**
     * Pending-Cash Accounting pass — posted the moment
     * ManualPaymentService::applyOrDeferInvoice()/applyOrDeferInstallment()
     * defers an ambiguous payment to a PendingPaymentAllocation. The
     * cash is genuinely received; only the fee/cost split is unknown.
     * Dr Operating Cash / Cr UnappliedOperatingFundsLiability for the
     * full payment amount — never a partial amount, since $pending's
     * own amount_cents always equals payment.amount_cents by
     * construction (a payment is deferred in full or not at all, this
     * pass introduces no partial-deferral concept).
     *
     * Idempotent on the payment: a retried submit() (same idempotency
     * key) never re-enters applyOrDeferInvoice()/applyOrDeferInstallment()
     * at all (ManualPaymentService's own idempotent-replay early
     * return), so this never double-posts in practice — the
     * idempotency key here is nonetheless keyed to the payment, not a
     * caller-supplied value, matching every other method in this
     * class.
     */
    public function recordUnappliedFundsReceived(Firm $firm, Payment $payment, PendingPaymentAllocation $pending): ?AccountingJournalEntry
    {
        if (! $this->isAccountingApplicable($firm)) {
            return null;
        }

        $cash = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::OperatingCash);
        $liability = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::UnappliedOperatingFundsLiability);

        return $this->posting->post(
            $firm,
            AccountingJournalSourceType::UnappliedFundsReceived,
            "Cash received, allocation pending — payment #{$payment->id}",
            now(),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => $payment->amount_cents, 'credit_cents' => 0, 'client_id' => $payment->client_id, 'matter_id' => $payment->matter_id],
                ['chart_of_account_id' => $liability->id, 'debit_cents' => 0, 'credit_cents' => $payment->amount_cents, 'client_id' => $payment->client_id, 'matter_id' => $payment->matter_id],
            ],
            [
                'payment_id' => $payment->id,
                'invoice_id' => $pending->invoice_id,
                'pending_payment_allocation_id' => $pending->id,
            ],
            idempotencyKey: "unapplied_funds_received:payment:{$payment->id}",
        );
    }

    /**
     * Pending-Cash Accounting pass — posted by
     * PaymentAllocationResolutionService::resolve() once an authorized
     * user has supplied the fee/cost split. Never debits Operating Cash
     * again (that leg was already posted by
     * recordUnappliedFundsReceived()); this entry only reclassifies the
     * liability into the correct revenue bucket(s): Dr
     * UnappliedOperatingFundsLiability for the full resolved amount,
     * Cr LegalFeeRevenue if feeCents > 0, Cr CostReimbursementRevenue
     * if costCents > 0 — mirroring recordFeeEarned()'s own
     * feeCents/costCents pattern, just without the cash leg.
     */
    public function recordUnappliedFundsResolved(Firm $firm, Payment $payment, PendingPaymentAllocation $pending, int $feeCents, int $costCents): ?AccountingJournalEntry
    {
        if (! $this->isAccountingApplicable($firm)) {
            return null;
        }

        $liability = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::UnappliedOperatingFundsLiability);

        $postings = [
            ['chart_of_account_id' => $liability->id, 'debit_cents' => $feeCents + $costCents, 'credit_cents' => 0, 'client_id' => $payment->client_id, 'matter_id' => $payment->matter_id],
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
            AccountingJournalSourceType::UnappliedFundsResolved,
            "Allocation resolved — payment #{$payment->id}",
            now(),
            $postings,
            [
                'payment_id' => $payment->id,
                'invoice_id' => $pending->invoice_id,
                'pending_payment_allocation_id' => $pending->id,
            ],
            idempotencyKey: "unapplied_funds_resolved:payment:{$payment->id}",
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
     *
     * Pending-Cash Accounting pass, section 6 — $feeCents/$costCents are
     * optional. When both null (the overwhelming common case: every
     * call site that predates this pass, and every fee-only payment),
     * this posts EXACTLY the same two-line entry it always has (cash
     * credit, single LegalFeeRevenue debit for the full $amountCents) —
     * no behavior change for any existing call site. When supplied
     * (feeCents + costCents === amountCents, always resolved by the
     * caller from the payment's own recognized PaymentAllocation
     * composition — never guessed here), reverses each recognized
     * bucket separately: Dr LegalFeeRevenue for feeCents, Dr
     * CostReimbursementRevenue for costCents, Cr Cash for the total.
     * Mirrors recordFeeEarned()'s own feeCents/costCents pattern in the
     * opposite direction.
     */
    public function recordCashOut(
        Firm $firm,
        AccountingJournalSourceType $sourceType,
        string $description,
        int $amountCents,
        string $idempotencyKey,
        ?int $paymentId = null,
        ?int $invoiceId = null,
        ?int $feeCents = null,
        ?int $costCents = null,
    ): ?AccountingJournalEntry {
        if (! in_array($sourceType, [AccountingJournalSourceType::Refund, AccountingJournalSourceType::Chargeback], true)) {
            throw new \InvalidArgumentException('recordCashOut() only supports Refund and Chargeback source types.');
        }

        if (($feeCents === null) !== ($costCents === null)) {
            throw new \InvalidArgumentException('feeCents and costCents must be supplied together or not at all.');
        }

        if ($feeCents !== null && $costCents !== null && $feeCents + $costCents !== $amountCents) {
            throw new \InvalidArgumentException("feeCents ({$feeCents}) plus costCents ({$costCents}) must equal amountCents ({$amountCents}).");
        }

        if (! $this->isAccountingApplicable($firm)) {
            return null;
        }

        $cash = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::OperatingCash);

        $postings = [
            ['chart_of_account_id' => $cash->id, 'debit_cents' => 0, 'credit_cents' => $amountCents],
        ];

        if ($feeCents === null) {
            $revenue = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::LegalFeeRevenue);
            $postings[] = ['chart_of_account_id' => $revenue->id, 'debit_cents' => $amountCents, 'credit_cents' => 0];
        } else {
            if ($feeCents > 0) {
                $revenue = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::LegalFeeRevenue);
                $postings[] = ['chart_of_account_id' => $revenue->id, 'debit_cents' => $feeCents, 'credit_cents' => 0];
            }

            if ($costCents > 0) {
                $costRevenue = $this->chartOfAccounts->requireByPurpose($firm, ChartOfAccountPurpose::CostReimbursementRevenue);
                $postings[] = ['chart_of_account_id' => $costRevenue->id, 'debit_cents' => $costCents, 'credit_cents' => 0];
            }
        }

        return $this->posting->post(
            $firm,
            $sourceType,
            $description,
            now(),
            $postings,
            ['payment_id' => $paymentId, 'invoice_id' => $invoiceId],
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * The one place revenue is actually posted. feeCents/costCents are
     * always supplied by the caller, already resolved — never
     * re-derived here. When costCents is 0 (the overwhelming common
     * case: a fee-only invoice, or any call site that predates the
     * Mixed-Invoice Revenue Allocation pass), this posts EXACTLY the
     * same two-line entry it always has (cash debit, single
     * LegalFeeRevenue credit) — a CostReimbursementRevenue leg is only
     * ever added when costCents > 0, so no behavior changes for any
     * existing fee-only call site.
     */
    private function recordFeeEarned(
        Firm $firm,
        Payment $payment,
        AccountingJournalSourceType $sourceType,
        string $idempotencyKey,
        string $description,
        int $feeCents,
        int $costCents,
        ?int $invoiceId = null,
        ?int $trustTransferRequestId = null,
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
            ['payment_id' => $payment->id, 'invoice_id' => $invoiceId, 'trust_transfer_request_id' => $trustTransferRequestId],
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
