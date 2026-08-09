<?php

namespace App\Services;

use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentStatus;
use App\Enums\WebhookEventType;
use App\Exceptions\PaymentBlockedException;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\ManualPaymentRecord;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\PaymentPlanInstallment;
use App\Models\PendingPaymentAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ManualPaymentService — the ONLY path that creates a manual payment.
 * A canonical Payment row is always created first (status=Initiated)
 * within one transaction; PaymentClassificationService then decides
 * and records the classification in place. A ManualPaymentRecord is
 * created ONLY when the payment is accepted — it links to the
 * canonical Payment and is never a second ledger (project rule 3).
 *
 * Idempotency (project rule 7): submit() is idempotent on
 * (firm_id, idempotencyKey) — a resubmission with the same key returns
 * (or replays the exception of) the original result rather than
 * creating a second row. The partial unique index on
 * payments(firm_id, idempotency_key) is the concurrency-safe backstop
 * if two requests race past the initial check simultaneously.
 *
 * Phase 14b addition: fires payment.recorded exactly once, ONLY inside
 * the $result->accepted branch (never for a blocked payment, and never
 * on the idempotent-replay early return above — that return happens
 * before this branch is ever reached, so a repeated idempotency key
 * cannot double-fire), registered via DB::afterCommit() from inside
 * the existing DB::transaction().
 *
 * Mixed-Invoice Revenue Allocation pass — the optional $purposeHint
 * (PaymentRequestPurpose::EarnedFee/FilingCostReimbursement/
 * PaymentPlanInstallment — never TrustDeposit, which never reaches
 * this service at all) constrains how an invoice/installment-targeted
 * payment's revenue is split when the target is a mixed invoice (fee
 * lines + reimbursable-expense lines). See applyOrDeferInvoice()/
 * applyOrDeferInstallment()'s own docblocks: when the split cannot be
 * determined safely, the Payment itself still succeeds (real money was
 * received) but its application to the invoice/installment and its
 * journal posting are BOTH deferred to a PendingPaymentAllocation for
 * an authorized human to resolve — never guessed.
 */
class ManualPaymentService
{
    public function __construct(
        private PaymentClassificationService $classification,
        private PaymentApplicationService $application,
        private TimelineEventRecorder $timeline,
        private OperatingJournalRecorderService $journal,
    ) {}

    public function submit(
        Firm $firm,
        Client $client,
        int $amountCents,
        ManualPaymentMethod $method,
        PaymentClassification $requestedClassification,
        string $idempotencyKey,
        ?Matter $matter = null,
        ?Invoice $invoice = null,
        ?PaymentPlanInstallment $installment = null,
        ?User $recordedBy = null,
        ?string $externalReference = null,
        ?string $methodReference = null,
        ?string $notes = null,
        ?PaymentRequestPurpose $purposeHint = null,
    ): Payment {
        $payment = (new TenantContextService)->runWithFirmContext($firm, function () use (
            $firm, $client, $matter, $invoice, $installment, $amountCents, $method,
            $requestedClassification, $idempotencyKey, $recordedBy, $externalReference,
            $methodReference, $notes, $purposeHint,
        ) {
            $existing = Payment::query()
                ->where('firm_id', $firm->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                // Idempotent replay: same key always returns the
                // original outcome, never a second row, and never
                // fires a second payment.recorded webhook event.
                return $existing;
            }

            $payment = Payment::create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter?->id,
                'invoice_id' => $invoice?->id,
                'payment_plan_installment_id' => $installment?->id,
                'amount_cents' => $amountCents,
                'payment_method' => $method,
                'payment_classification' => $requestedClassification,
                'status' => PaymentStatus::Initiated,
                'external_reference' => $externalReference,
                'idempotency_key' => $idempotencyKey,
                'recorded_by' => $recordedBy?->id,
            ]);

            $result = $this->classification->classify($firm, $requestedClassification);
            $this->classification->recordDecision($payment, $requestedClassification, $result, $recordedBy);
            $payment = $payment->fresh();

            if ($result->accepted) {
                ManualPaymentRecord::create([
                    'payment_id' => $payment->id,
                    'received_by' => $recordedBy?->id,
                    'received_at' => now(),
                    'method_reference' => $methodReference,
                    'notes' => $notes,
                ]);

                if ($installment) {
                    $this->applyOrDeferInstallment($firm, $payment, $installment, $purposeHint);
                } elseif ($invoice) {
                    // Already inside this method's own runWithFirmContext
                    // wrap (see above), so a plain fresh() here is safe.
                    $this->applyOrDeferInvoice($firm, $payment, $invoice->fresh(), $purposeHint);
                }

                $this->timeline->record($firm, 'payment_recorded', $payment, $recordedBy, [
                    'payment_id' => $payment->id,
                    'amount_cents' => $amountCents,
                ]);

                $payment = $payment->fresh();

                DB::afterCommit(function () use ($firm, $payment) {
                    try {
                        app(WebhookEventRecorderService::class)->record($firm, WebhookEventType::PaymentRecorded, $payment);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });
            } else {
                $this->timeline->record($firm, 'payment_blocked', $payment, $recordedBy, [
                    'payment_id' => $payment->id,
                    'reason' => $result->rejectionReason,
                ]);
            }

            return $payment->fresh();
        });

        if (! $payment->isAcceptedOperatingPayment()) {
            throw new PaymentBlockedException($payment, $payment->rejection_reason ?? 'Payment was blocked.');
        }

        return $payment;
    }

    /**
     * Mixed-Invoice Revenue Allocation pass — resolves the fee/cost
     * split for this invoice-targeted payment. When resolved, applies
     * AND posts exactly as before this phase (zero behavior change for
     * every fee-only invoice — the overwhelming common case). When
     * ambiguous, defers BOTH the invoice application and the journal
     * posting to a PendingPaymentAllocation: the Payment itself stays
     * genuinely Succeeded (real money was received), but
     * invoices.amount_paid_cents is deliberately left untouched and no
     * journal entry exists until an authorized human resolves the
     * split (PaymentAllocationResolutionService).
     */
    private function applyOrDeferInvoice(Firm $firm, Payment $payment, Invoice $invoice, ?PaymentRequestPurpose $purposeHint): void
    {
        $decision = $this->application->resolveInvoiceRevenueAllocation($invoice, $payment->amount_cents, $purposeHint);

        if ($decision->isAmbiguous()) {
            PendingPaymentAllocation::create([
                'firm_id' => $firm->id,
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount_cents' => $payment->amount_cents,
                'reason' => $decision->reason,
            ]);

            return;
        }

        $this->application->applyToInvoice($payment, $invoice);
        $this->journal->recordInvoicePaymentApplied($firm, $payment->fresh(), $invoice->fresh(), $decision->feeCents, $decision->costCents);
        $this->application->recordRevenueAllocation($firm, $payment->fresh(), $invoice->fresh(), $decision->feeCents, $decision->costCents);
    }

    /**
     * Same deferral principle as applyOrDeferInvoice(), scoped to an
     * installment's own underlying invoice (installment.paymentPlan.invoice)
     * when one exists. An installment with no underlying invoice (a
     * standalone payment plan) has no lines/fee-cost concept at all —
     * unchanged, always posts 100% to LegalFeeRevenue exactly as
     * before this phase.
     */
    private function applyOrDeferInstallment(Firm $firm, Payment $payment, PaymentPlanInstallment $installment, ?PaymentRequestPurpose $purposeHint): void
    {
        $underlyingInvoice = $installment->paymentPlan?->invoice;

        if ($underlyingInvoice === null) {
            $this->application->applyToInstallment($payment, $installment);
            $this->journal->recordInstallmentPaymentApplied($firm, $payment->fresh(), $installment->fresh(), $payment->amount_cents, 0);

            return;
        }

        $decision = $this->application->resolveInvoiceRevenueAllocation($underlyingInvoice, $payment->amount_cents, $purposeHint);

        if ($decision->isAmbiguous()) {
            PendingPaymentAllocation::create([
                'firm_id' => $firm->id,
                'payment_id' => $payment->id,
                'invoice_id' => $underlyingInvoice->id,
                'payment_plan_installment_id' => $installment->id,
                'amount_cents' => $payment->amount_cents,
                'reason' => $decision->reason,
            ]);

            return;
        }

        $this->application->applyToInstallment($payment, $installment);
        $this->journal->recordInstallmentPaymentApplied($firm, $payment->fresh(), $installment->fresh(), $decision->feeCents, $decision->costCents);
        $this->application->recordRevenueAllocation($firm, $payment->fresh(), $underlyingInvoice, $decision->feeCents, $decision->costCents, installment: $installment->fresh());
    }
}
