<?php

namespace App\Services;

use App\Enums\PlatformPaymentAttemptStatus;
use App\Enums\PlatformPaymentStatus;
use App\Models\PlatformInvoice;
use App\Models\PlatformPayment;
use App\Services\Stripe\StripeGateway;
use Illuminate\Support\Facades\DB;

/**
 * PlatformPaymentService — the only place platform_payments rows are
 * created. Reuses Phase 3's PaymentClassification (via
 * PlatformBillingClassificationService, always OperatingPayment) BEFORE
 * ever calling StripeGateway::createPaymentIntent() (project rule 9).
 * Stripe (here, always FakeStripeGateway) confirms money movement only
 * — this service decides what that confirmation MEANS for the invoice
 * (marks it Paid on success). Every attempt, successful or not, is
 * recorded via PlatformPaymentAttemptService first.
 */
class PlatformPaymentService
{
    public function __construct(
        private PlatformBillingClassificationService $classificationService,
        private PlatformPaymentAttemptService $attemptService,
        private PlatformInvoiceService $invoiceService,
        private PlatformBillingEventService $billingEventService,
    ) {
    }

    public function attemptPayment(PlatformInvoice $invoice, StripeGateway $gateway): ?PlatformPayment
    {
        return DB::transaction(function () use ($invoice, $gateway) {
            // Classification happens BEFORE any PaymentIntent creation
            // (project rule 9). Platform billing can only ever be
            // OperatingPayment — see that service's docblock.
            $classification = $this->classificationService->classify();

            $attemptNumber = $this->attemptService->nextAttemptNumber($invoice->billingAccount, $invoice);

            $result = $gateway->createPaymentIntent(
                amountCents: $invoice->total_cents,
                currency: 'usd',
                metadata: ['platform_invoice_id' => $invoice->id],
            );

            if ($result['status'] !== 'succeeded') {
                $this->attemptService->recordAttempt(
                    billingAccount: $invoice->billingAccount,
                    invoice: $invoice,
                    status: PlatformPaymentAttemptStatus::Failed,
                    attemptNumber: $attemptNumber,
                    gatewayResponseCode: $result['id'] ?? null,
                    failureReason: $result['failure_reason'] ?? 'unknown',
                );

                $this->billingEventService->log($invoice->billingAccount, 'payment_attempt_failed', [
                    'platform_invoice_id' => $invoice->id,
                ]);

                return null;
            }

            $this->attemptService->recordAttempt(
                billingAccount: $invoice->billingAccount,
                invoice: $invoice,
                status: PlatformPaymentAttemptStatus::Succeeded,
                attemptNumber: $attemptNumber,
                gatewayResponseCode: $result['id'] ?? null,
            );

            $payment = PlatformPayment::create([
                'billing_account_id' => $invoice->billing_account_id,
                'platform_invoice_id' => $invoice->id,
                'status' => PlatformPaymentStatus::Succeeded,
                'classification' => $classification,
                'amount_cents' => $invoice->total_cents,
                'gateway_payment_ref' => $result['id'],
                'attempted_at' => now(),
                'succeeded_at' => now(),
            ]);

            $this->invoiceService->markPaid($invoice);

            $this->billingEventService->log($invoice->billingAccount, 'payment_succeeded', [
                'platform_invoice_id' => $invoice->id,
                'platform_payment_id' => $payment->id,
            ]);

            return $payment;
        });
    }
}
