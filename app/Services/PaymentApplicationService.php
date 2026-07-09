<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentPlanInstallmentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentPlanInstallment;

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
 */
class PaymentApplicationService
{
    public function __construct(
        private PaymentPlanService $plans,
        private TimelineEventRecorder $timeline,
    ) {
    }

    public function applyToInstallment(Payment $payment, PaymentPlanInstallment $installment): void
    {
        if (! $payment->isAcceptedOperatingPayment()) {
            throw new \RuntimeException('Only an accepted operating payment can be applied to an installment.');
        }

        if ($payment->payment_plan_installment_id !== $installment->id) {
            throw new \RuntimeException('This payment is not targeted at the given installment.');
        }

        $paidAmount = $installment->paid_amount_cents + $payment->amount_cents;
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
                'amount_cents' => $payment->amount_cents,
            ],
        ]);

        $this->timeline->record($plan->firm, 'payment_plan_installment_paid', $installment);

        $this->plans->markCompletedIfAllInstallmentsPaid($plan->fresh());
    }

    public function applyToInvoice(Payment $payment, Invoice $invoice): void
    {
        if (! $payment->isAcceptedOperatingPayment()) {
            throw new \RuntimeException('Only an accepted operating payment can be applied to an invoice.');
        }

        if ($payment->invoice_id !== $invoice->id) {
            throw new \RuntimeException('This payment is not targeted at the given invoice.');
        }

        if (! in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Approved, InvoiceStatus::PartiallyPaid], true)) {
            throw new \RuntimeException('Payments cannot apply to an invoice that has not been sent/approved.');
        }

        $paidAmount = $invoice->amount_paid_cents + $payment->amount_cents;
        $status = $paidAmount >= $invoice->total_cents ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid;

        (new TenantContextService())->runWithFirmContext($invoice->firm_id, fn () => $invoice->update([
            'amount_paid_cents' => $paidAmount,
            'status' => $status,
        ]));

        $this->timeline->record($invoice->firm, 'invoice_payment_applied', $invoice, null, [
            'payment_id' => $payment->id,
            'amount_cents' => $payment->amount_cents,
        ]);
    }
}
