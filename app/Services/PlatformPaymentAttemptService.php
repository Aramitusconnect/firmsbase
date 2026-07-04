<?php

namespace App\Services;

use App\Enums\PlatformPaymentAttemptStatus;
use App\Models\BillingAccount;
use App\Models\PlatformInvoice;
use App\Models\PlatformPaymentAttempt;

/**
 * PlatformPaymentAttemptService — the only place platform_payment_attempts
 * rows are created. Records EVERY attempt to collect a platform
 * invoice via StripeGateway, including failed ones — distinct from
 * PlatformPayment, which only ever represents a settled/succeeded (or
 * explicitly failed-terminal) payment record.
 */
class PlatformPaymentAttemptService
{
    public function recordAttempt(
        BillingAccount $billingAccount,
        ?PlatformInvoice $invoice,
        PlatformPaymentAttemptStatus $status,
        int $attemptNumber,
        ?string $gatewayResponseCode = null,
        ?string $failureReason = null,
    ): PlatformPaymentAttempt {
        return PlatformPaymentAttempt::create([
            'billing_account_id' => $billingAccount->id,
            'platform_invoice_id' => $invoice?->id,
            'status' => $status,
            'attempt_number' => $attemptNumber,
            'gateway_response_code' => $gatewayResponseCode,
            'failure_reason' => $failureReason,
            'attempted_at' => now(),
        ]);
    }

    public function nextAttemptNumber(BillingAccount $billingAccount, ?PlatformInvoice $invoice): int
    {
        if (! $invoice) {
            return 1;
        }

        return 1 + (int) $invoice->paymentAttempts()->max('attempt_number');
    }
}
