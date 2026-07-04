<?php

namespace App\Services;

use App\Enums\PlatformInvoiceStatus;
use App\Enums\PlatformPaymentStatus;
use App\Models\CommissionEvent;
use App\Models\PlatformBillingEvent;
use App\ValueObjects\CommissionEligibilityResult;

/**
 * CommissionEligibilityService — decides whether a CommissionEvent is
 * payable. Reads ONLY Phase 6 platform billing records
 * (platform_invoices/platform_payments) and platform_billing_events —
 * NEVER Phase 3 firm-client billing (invoices/payments/payment_plans/
 * manual_payment_records). Does NOT modify or read any new/extended
 * Phase 6 enum case — PlatformInvoiceStatus/PlatformPaymentStatus/
 * PlatformRefundStatus/PlatformPaymentAttemptStatus are used exactly as
 * Phase 6 defined them. Disqualifying conditions that Phase 6's enums
 * cannot express (disputed, charged back, cancelled, blocked, holding
 * period, refund events) are represented as platform_billing_events
 * rows instead, per the approved Phase 7 decision — this keeps Phase 6
 * completely untouched.
 */
class CommissionEligibilityService
{
    private const DISQUALIFYING_EVENT_TYPES = [
        'payment_disputed',
        'payment_charged_back',
        'invoice_cancelled',
        'invoice_blocked',
        'account_blocked',
        'payment_holding_period',
        'refund_created',
        'refund_processed',
    ];

    public function evaluate(CommissionEvent $commissionEvent): CommissionEligibilityResult
    {
        $reasons = [];

        $invoice = $commissionEvent->platformInvoice;
        if ($invoice !== null && $invoice->status !== PlatformInvoiceStatus::Paid) {
            $reasons[] = 'platform_invoice_unpaid';
        }

        $payment = $commissionEvent->platformPayment;
        if ($payment !== null) {
            if ($payment->status === PlatformPaymentStatus::Failed) {
                $reasons[] = 'platform_payment_failed';
            }

            if (in_array($payment->status, [PlatformPaymentStatus::Refunded, PlatformPaymentStatus::PartiallyRefunded], true)) {
                $reasons[] = 'platform_payment_refunded';
            }
        }

        if ($commissionEvent->holding_period_ends_at !== null && $commissionEvent->holding_period_ends_at->isFuture()) {
            $reasons[] = 'holding_period_active';
        }

        $billingEventReasons = $this->disqualifyingBillingEventTypes($commissionEvent);
        $reasons = [...$reasons, ...$billingEventReasons];

        if ($reasons !== []) {
            return CommissionEligibilityResult::blocked($reasons);
        }

        return CommissionEligibilityResult::payable();
    }

    /**
     * @return string[]
     */
    private function disqualifyingBillingEventTypes(CommissionEvent $commissionEvent): array
    {
        return PlatformBillingEvent::query()
            ->where('billing_account_id', $commissionEvent->billing_account_id)
            ->whereIn('event_type', self::DISQUALIFYING_EVENT_TYPES)
            ->pluck('event_type')
            ->unique()
            ->values()
            ->all();
    }
}
