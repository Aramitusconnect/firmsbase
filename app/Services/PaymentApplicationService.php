<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentPlanInstallmentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentPlanInstallment;
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

        if ($totalAllocated > $payment->amount_cents) {
            throw new \RuntimeException('Total allocations cannot exceed the payment amount; a payment cannot be over-applied.');
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
}
