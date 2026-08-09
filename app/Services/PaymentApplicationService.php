<?php

namespace App\Services;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentPlanInstallmentStatus;
use App\Enums\PaymentRequestPurpose;
use App\Exceptions\InvoiceRevenueAllocationExceedsRemainingBalanceException;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentPlanInstallment;
use App\ValueObjects\InvoiceRevenueAllocationDecision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PaymentApplicationService — the ONLY place a canonical Payment's
 * amount is applied against a PaymentPlanInstallment or an Invoice.
 * "Installment payments are canonical payments applied against
 * installments; the payment plan is a schedule, never a parallel
 * ledger" (PDF Controls and Rules) — paid_amount_cents/amount_paid_
 * cents on the installment/invoice are caches this service recomputes,
 * never independent figures.
 *
 * A single payment applies to exactly one target: an installment (if
 * payment_plan_installment_id is set) or an invoice directly (if
 * invoice_id is set with no installment) — never both, to avoid
 * double-counting the same money on two schedules.
 *
 * applySplit() (Phase F) is the one exception: a payment with NEITHER
 * invoice_id nor payment_plan_installment_id set may instead be
 * divided across multiple invoices/installments via payment_allocations.
 * Audited before building (per the master accounting-foundation
 * prompt's own gate): no existing entry point creates a multi-target
 * payment today, so nothing currently calls applySplit() — it exists
 * as a tested, available extension of THIS canonical service, not a
 * second payment-application service.
 */
class PaymentApplicationService
{
    public function __construct(
        private PaymentPlanService $plans,
        private TimelineEventRecorder $timeline,
    ) {}

    public function applyToInstallment(Payment $payment, PaymentPlanInstallment $installment): void
    {
        if (! $payment->isAcceptedOperatingPayment()) {
            throw new \RuntimeException('Only an accepted operating payment can be applied to an installment.');
        }

        if ($payment->payment_plan_installment_id !== $installment->id) {
            throw new \RuntimeException('This payment is not targeted at the given installment.');
        }

        $this->applyAmountToInstallment($payment, $installment, $payment->amount_cents);
    }

    public function applyToInvoice(Payment $payment, Invoice $invoice): void
    {
        if (! $payment->isAcceptedOperatingPayment()) {
            throw new \RuntimeException('Only an accepted operating payment can be applied to an invoice.');
        }

        if ($payment->invoice_id !== $invoice->id) {
            throw new \RuntimeException('This payment is not targeted at the given invoice.');
        }

        $this->applyAmountToInvoice($payment, $invoice, $payment->amount_cents);
    }

    /**
     * Splits a single payment across multiple invoices/installments.
     * Only usable when the payment does NOT already have a single
     * direct target (invoice_id/payment_plan_installment_id both
     * null) — a payment is either single-target (applyToInvoice/
     * applyToInstallment) or split (this method), never both, and
     * never split twice.
     *
     * @param  array<int, array{invoice?: Invoice, installment?: PaymentPlanInstallment, amount_cents: int}>  $allocations
     * @return Collection<int, PaymentAllocation>
     */
    public function applySplit(Payment $payment, array $allocations): Collection
    {
        if (! $payment->isAcceptedOperatingPayment()) {
            throw new \RuntimeException('Only an accepted operating payment can be split-allocated.');
        }

        if ($payment->invoice_id !== null || $payment->payment_plan_installment_id !== null) {
            throw new \RuntimeException('This payment already has a single direct target and cannot also be split-allocated.');
        }

        if (PaymentAllocation::query()->where('payment_id', $payment->id)->exists()) {
            throw new \RuntimeException('This payment has already been split-allocated; it cannot be allocated a second time.');
        }

        if (empty($allocations)) {
            throw new \InvalidArgumentException('At least one allocation is required.');
        }

        $totalAllocated = 0;
        $seenTargets = [];

        foreach ($allocations as $allocation) {
            $amount = $allocation['amount_cents'] ?? 0;

            if ($amount <= 0) {
                throw new \InvalidArgumentException('Each allocation amount must be positive.');
            }

            $totalAllocated += $amount;

            $invoice = $allocation['invoice'] ?? null;
            $installment = $allocation['installment'] ?? null;

            if (($invoice === null) === ($installment === null)) {
                throw new \InvalidArgumentException('Each allocation must target exactly one of invoice or installment, never both/neither.');
            }

            $targetKey = $invoice !== null ? "invoice:{$invoice->id}" : "installment:{$installment->id}";

            if (isset($seenTargets[$targetKey])) {
                throw new \InvalidArgumentException('The same target cannot receive two allocations in a single split.');
            }

            $seenTargets[$targetKey] = true;

            if ($invoice !== null) {
                if ((int) $invoice->firm_id !== (int) $payment->firm_id) {
                    throw new \RuntimeException('Cannot allocate to an invoice belonging to a different firm.');
                }

                if ((int) $invoice->client_id !== (int) $payment->client_id) {
                    throw new \RuntimeException('Cannot allocate to an invoice belonging to a different client.');
                }
            } else {
                $plan = $installment->paymentPlan;

                if ((int) $plan->firm_id !== (int) $payment->firm_id) {
                    throw new \RuntimeException('Cannot allocate to an installment belonging to a different firm.');
                }

                if ((int) $plan->client_id !== (int) $payment->client_id) {
                    throw new \RuntimeException('Cannot allocate to an installment belonging to a different client.');
                }
            }
        }

        // Accounting Integrity Hardening Pass, item 9: full allocation
        // is REQUIRED, not merely capped. An earlier draft allowed
        // sum(allocations) <= payment amount, leaving an under-allocated
        // remainder with no defined destination — it could not become an
        // unapplied client credit (no such liability concept exists
        // anywhere in this codebase — confirmed by repository search)
        // and silently classifying it as revenue would be exactly the
        // kind of invented, undisclosed policy this hardening pass
        // forbids. Requiring an exact match eliminates the residual
        // entirely rather than guessing at its treatment; a caller with
        // genuinely leftover money must either adjust its allocation
        // list to sum exactly, or apply/refund the remainder through an
        // existing, already-audited path (a smaller single-target
        // applyToInvoice()/applyToInstallment(), or
        // OperatingPaymentRefundService).
        if ($totalAllocated !== $payment->amount_cents) {
            throw new \RuntimeException('Total allocations must exactly equal the payment amount; a split payment cannot leave any amount unapplied or exceed the payment.');
        }

        // Deliberately NOT self-wrapped in runWithFirmContext(), for the
        // same reason as applyToInvoice()/applyToInstallment(): the
        // caller is expected to have already established firm context.
        return DB::transaction(function () use ($payment, $allocations) {
            $created = new Collection;

            foreach ($allocations as $allocation) {
                $amount = $allocation['amount_cents'];
                $invoice = $allocation['invoice'] ?? null;
                $installment = $allocation['installment'] ?? null;

                if ($invoice !== null) {
                    $this->applyAmountToInvoice($payment, $invoice, $amount);
                } else {
                    $this->applyAmountToInstallment($payment, $installment, $amount);
                }

                $created->push(PaymentAllocation::create([
                    'firm_id' => $payment->firm_id,
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice?->id,
                    'payment_plan_installment_id' => $installment?->id,
                    'amount_cents' => $amount,
                    'created_at' => now(),
                ]));
            }

            return $created;
        });
    }

    /**
     * Phase G — the reversal-direction counterpart to
     * applyAmountToInvoice()/applyAmountToInstallment(), used by
     * OperatingPaymentRefundService/OperatingChargebackService.
     * PaymentApplicationService remains the ONLY writer of
     * invoices.amount_paid_cents/status and payment_plan_installments.
     * paid_amount_cents/status in either direction — refund/chargeback
     * services never touch those columns directly.
     */
    public function reverseAmountFromInvoice(Invoice $invoice, int $amountCents): void
    {
        $paidAmount = max(0, $invoice->amount_paid_cents - $amountCents);
        $status = $paidAmount <= 0
            ? InvoiceStatus::Sent
            : ($paidAmount >= $invoice->total_cents ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid);

        $invoice->update([
            'amount_paid_cents' => $paidAmount,
            'status' => $status,
        ]);

        $this->timeline->record($invoice->firm, 'invoice_payment_reversed', $invoice, null, [
            'amount_cents' => $amountCents,
        ]);
    }

    public function reverseAmountFromInstallment(PaymentPlanInstallment $installment, int $amountCents): void
    {
        $paidAmount = max(0, $installment->paid_amount_cents - $amountCents);

        $installment->update([
            'paid_amount_cents' => $paidAmount,
            'status' => $paidAmount <= 0 ? PaymentPlanInstallmentStatus::Due : PaymentPlanInstallmentStatus::PartiallyPaid,
            'paid_at' => null,
        ]);
    }

    /**
     * Shared by applyToInstallment() (the full payment amount) and
     * applySplit() (one allocation's amount) — the exact same
     * paid-amount/status computation either way, never duplicated.
     */
    private function applyAmountToInstallment(Payment $payment, PaymentPlanInstallment $installment, int $amountCents): void
    {
        $paidAmount = $installment->paid_amount_cents + $amountCents;
        $status = $paidAmount >= $installment->amount_cents
            ? PaymentPlanInstallmentStatus::Paid
            : PaymentPlanInstallmentStatus::PartiallyPaid;

        $installment->update([
            'paid_amount_cents' => $paidAmount,
            'status' => $status,
            'paid_at' => $status === PaymentPlanInstallmentStatus::Paid ? now() : null,
        ]);

        $plan = $installment->paymentPlan;

        $plan->events()->create([
            'firm_id' => $plan->firm_id,
            'event_type' => 'installment_paid',
            'metadata_json' => [
                'payment_plan_installment_id' => $installment->id,
                'payment_id' => $payment->id,
                'amount_cents' => $amountCents,
            ],
        ]);

        $this->timeline->record($plan->firm, 'payment_plan_installment_paid', $installment);

        $this->plans->markCompletedIfAllInstallmentsPaid($plan->fresh());
    }

    /**
     * Shared by applyToInvoice() (the full payment amount) and
     * applySplit() (one allocation's amount).
     */
    private function applyAmountToInvoice(Payment $payment, Invoice $invoice, int $amountCents): void
    {
        if (! in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Approved, InvoiceStatus::PartiallyPaid], true)) {
            throw new \RuntimeException('Payments cannot apply to an invoice that has not been sent/approved.');
        }

        $paidAmount = $invoice->amount_paid_cents + $amountCents;
        $status = $paidAmount >= $invoice->total_cents ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid;

        // Deliberately NOT self-wrapped in runWithFirmContext(): this
        // method is always called from within a caller that has
        // already established firm context for the whole operation
        // (ManualPaymentService::submit(), TrustTransferRequestService
        // ::apply()) — a nested runWithFirmContext() call here would
        // clear that context in its own finally block the moment this
        // method returns, breaking the caller's own subsequent reads
        // (Section 39A-3H finding).
        $invoice->update([
            'amount_paid_cents' => $paidAmount,
            'status' => $status,
        ]);

        $this->timeline->record($invoice->firm, 'invoice_payment_applied', $invoice, null, [
            'payment_id' => $payment->id,
            'amount_cents' => $amountCents,
        ]);
    }

    /**
     * Mixed-Invoice Revenue Allocation pass, item 1/2/5/6 — the single
     * decision point for how much of a given payment amount (about to
     * be applied to $invoice) belongs to LegalFeeRevenue vs.
     * CostReimbursementRevenue. A PURE computation: never writes
     * anything, never applies the payment, never posts a journal entry
     * — callers (ManualPaymentService::submit()) act on the returned
     * decision.
     *
     * Exactly three safe (non-ambiguous) cases, checked in order:
     *
     *   1. The invoice has no ReimbursableExpense lines at all (a
     *      fee-only invoice — the overwhelming common case) —
     *      everything is fee. Symmetric for a cost-only invoice.
     *   2. This amount, combined with everything already recognized
     *      for this invoice (derived from existing revenue_purpose-
     *      tagged PaymentAllocation rows — never re-derived from raw
     *      journal postings, which are a downstream consequence, not
     *      the source of truth for "how much has been allocated"),
     *      exactly finishes BOTH remaining buckets. There is no
     *      allocation policy to choose here: whatever is left, by
     *      definition, is what this payment funds.
     *   3. $purposeHint explicitly constrains this payment to ONE
     *      bucket (PaymentRequestPurpose::EarnedFee -> fee only,
     *      FilingCostReimbursement -> cost only) and the amount fits
     *      within that bucket's own remaining balance. Exceeding it
     *      throws InvoiceRevenueAllocationExceedsRemainingBalanceException
     *      — never silently reclassified into the other bucket.
     *
     * Every other case (no purpose hint, or
     * PaymentRequestPurpose::PaymentPlanInstallment with no defined
     * fee/cost mapping, on a mixed invoice, for an amount that doesn't
     * finish both buckets) is genuinely ambiguous — this codebase has
     * never defined a pro-rata/FIFO/fee-first/cost-first policy, and
     * per this phase's own master prompt, none may be invented here.
     * Returns InvoiceRevenueAllocationDecision::ambiguous() — the
     * caller must defer to a PendingPaymentAllocation instead of
     * posting anything.
     */
    public function resolveInvoiceRevenueAllocation(Invoice $invoice, int $amountCents, ?PaymentRequestPurpose $purposeHint): InvoiceRevenueAllocationDecision
    {
        $totals = $this->invoiceLineTotals($invoice);

        if ($totals['cost_lines_total_cents'] <= 0) {
            return InvoiceRevenueAllocationDecision::resolved($amountCents, 0);
        }

        if ($totals['fee_lines_total_cents'] <= 0) {
            return InvoiceRevenueAllocationDecision::resolved(0, $amountCents);
        }

        $remaining = $this->invoiceRevenueRemaining($invoice);
        $feeRemaining = $remaining['fee_remaining_cents'];
        $costRemaining = $remaining['cost_remaining_cents'];

        if ($amountCents === $feeRemaining + $costRemaining) {
            return InvoiceRevenueAllocationDecision::resolved($feeRemaining, $costRemaining);
        }

        if ($purposeHint === PaymentRequestPurpose::EarnedFee) {
            if ($amountCents > $feeRemaining) {
                throw new InvoiceRevenueAllocationExceedsRemainingBalanceException(
                    "Amount {$amountCents} exceeds the remaining legal-fee balance ({$feeRemaining}) on invoice #{$invoice->id}."
                );
            }

            return InvoiceRevenueAllocationDecision::resolved($amountCents, 0);
        }

        if ($purposeHint === PaymentRequestPurpose::FilingCostReimbursement) {
            if ($amountCents > $costRemaining) {
                throw new InvoiceRevenueAllocationExceedsRemainingBalanceException(
                    "Amount {$amountCents} exceeds the remaining cost-reimbursement balance ({$costRemaining}) on invoice #{$invoice->id}."
                );
            }

            return InvoiceRevenueAllocationDecision::resolved(0, $amountCents);
        }

        return InvoiceRevenueAllocationDecision::ambiguous(
            'A partial payment on a mixed invoice (legal-fee lines + reimbursable-expense lines) has no allocation instruction — this codebase defines no automatic fee-first/cost-first/pro-rata policy, so this payment awaits an authorized human allocation decision.'
        );
    }

    /**
     * Mixed-Invoice Revenue Allocation pass, item 8 — exposed publicly
     * so PaymentAllocationResolutionService can re-validate a proposed
     * fee/cost split against CURRENT remaining balances at resolution
     * time (other payments may have landed since the
     * PendingPaymentAllocation row was created), without re-deriving
     * this computation a second time.
     *
     * @return array{fee_lines_total_cents:int, cost_lines_total_cents:int, fee_remaining_cents:int, cost_remaining_cents:int}
     */
    public function invoiceRevenueRemaining(Invoice $invoice): array
    {
        $totals = $this->invoiceLineTotals($invoice);

        $feeRecognized = $this->invoiceRevenueRecognizedCents($invoice, ChartOfAccountPurpose::LegalFeeRevenue);
        $costRecognized = $this->invoiceRevenueRecognizedCents($invoice, ChartOfAccountPurpose::CostReimbursementRevenue);

        return [
            'fee_lines_total_cents' => $totals['fee_lines_total_cents'],
            'cost_lines_total_cents' => $totals['cost_lines_total_cents'],
            'fee_remaining_cents' => max(0, $totals['fee_lines_total_cents'] - $feeRecognized),
            'cost_remaining_cents' => max(0, $totals['cost_lines_total_cents'] - $costRecognized),
        ];
    }

    /**
     * @return array{fee_lines_total_cents:int, cost_lines_total_cents:int}
     */
    private function invoiceLineTotals(Invoice $invoice): array
    {
        $lines = $invoice->lines;
        $costLinesTotal = (int) $lines->where('line_type', InvoiceLineType::ReimbursableExpense)->sum('amount_cents');
        $feeLinesTotal = (int) $lines->sum('amount_cents') - $costLinesTotal;

        return ['fee_lines_total_cents' => $feeLinesTotal, 'cost_lines_total_cents' => $costLinesTotal];
    }

    /**
     * @return int total cents already recognized against $invoice for
     *             the given revenue purpose, derived exclusively from
     *             revenue_purpose-tagged PaymentAllocation rows (never
     *             from journal postings directly — see
     *             resolveInvoiceRevenueAllocation()'s own docblock).
     */
    private function invoiceRevenueRecognizedCents(Invoice $invoice, ChartOfAccountPurpose $purpose): int
    {
        return (int) PaymentAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->where('revenue_purpose', $purpose->value)
            ->sum('amount_cents');
    }

    /**
     * Mixed-Invoice Revenue Allocation pass, item 4 — the ONLY writer
     * of revenue_purpose-tagged PaymentAllocation rows. Called
     * immediately after a resolved (non-ambiguous)
     * InvoiceRevenueAllocationDecision has already been applied
     * (applyToInvoice()/applyToInstallment()) and posted
     * (OperatingJournalRecorderService) — never before, and never for
     * an ambiguous decision. One row per non-zero bucket, so a $500
     * payment split $300 fee / $200 cost produces two rows, both
     * referencing the same payment_id/invoice_id.
     */
    public function recordRevenueAllocation(
        Firm $firm,
        Payment $payment,
        Invoice $invoice,
        int $feeCents,
        int $costCents,
        ?PaymentPlanInstallment $installment = null,
    ): void {
        if ($feeCents > 0) {
            PaymentAllocation::create([
                'firm_id' => $firm->id,
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'payment_plan_installment_id' => $installment?->id,
                'amount_cents' => $feeCents,
                'revenue_purpose' => ChartOfAccountPurpose::LegalFeeRevenue->value,
                'created_at' => now(),
            ]);
        }

        if ($costCents > 0) {
            PaymentAllocation::create([
                'firm_id' => $firm->id,
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'payment_plan_installment_id' => $installment?->id,
                'amount_cents' => $costCents,
                'revenue_purpose' => ChartOfAccountPurpose::CostReimbursementRevenue->value,
                'created_at' => now(),
            ]);
        }
    }
}
