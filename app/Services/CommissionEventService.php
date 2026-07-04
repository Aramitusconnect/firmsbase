<?php

namespace App\Services;

use App\Enums\CommissionEventStatus;
use App\Models\BillingAccount;
use App\Models\CommissionEvent;
use App\Models\CommissionPlan;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Model;

/**
 * CommissionEventService — the only writer of commission_events. Keyed
 * to billing_account_id and Phase 6 platform billing records ONLY.
 * Never accepts or references a firm-client invoice/payment/payment
 * plan/manual payment record — those types are simply not accepted by
 * this service's signature. "Organization expansion attributes once to
 * the billing account" is enforced by the table's own unique constraint
 * on (billing_account_id, attributable_type, attributable_id,
 * event_type): attributeOnce() below relies on that constraint via
 * firstOrCreate rather than re-implementing the check in PHP.
 */
class CommissionEventService
{
    public function __construct(
        private readonly CommissionEligibilityService $eligibilityService,
    ) {
    }

    /**
     * Creates a commission event attributed to $attributable exactly
     * once per (billing account, attributable, event type). A second
     * call with the same attribution returns the original row untouched.
     */
    public function attributeOnce(
        BillingAccount $billingAccount,
        CommissionPlan $commissionPlan,
        Model $attributable,
        \App\Enums\CommissionEventType $eventType,
        int $amountCents,
        ?PlatformAdmin $platformAdmin = null,
        ?int $platformInvoiceId = null,
        ?int $platformPaymentId = null,
    ): CommissionEvent {
        $commissionEvent = CommissionEvent::query()->firstOrCreate(
            [
                'billing_account_id' => $billingAccount->id,
                'attributable_type' => $attributable::class,
                'attributable_id' => $attributable->id,
                'event_type' => $eventType->value,
            ],
            [
                'commission_plan_id' => $commissionPlan->id,
                'platform_admin_id' => $platformAdmin?->id,
                'platform_invoice_id' => $platformInvoiceId,
                'platform_payment_id' => $platformPaymentId,
                'status' => CommissionEventStatus::Pending,
                'amount_cents' => $amountCents,
                'holding_period_ends_at' => now()->addDays($commissionPlan->holding_period_days),
            ],
        );

        return $this->refreshEligibility($commissionEvent);
    }

    public function refreshEligibility(CommissionEvent $commissionEvent): CommissionEvent
    {
        $result = $this->eligibilityService->evaluate($commissionEvent);

        $commissionEvent->update([
            'status' => $result->status,
            'blocked_reason' => $result->payable ? null : implode(',', $result->disqualifyingReasons),
        ]);

        return $commissionEvent->fresh();
    }

    public function markPaid(CommissionEvent $commissionEvent): CommissionEvent
    {
        $commissionEvent->update([
            'status' => CommissionEventStatus::Paid,
            'paid_at' => now(),
        ]);

        return $commissionEvent->fresh();
    }

    public function reverse(CommissionEvent $commissionEvent, string $reason): CommissionEvent
    {
        $commissionEvent->update([
            'status' => CommissionEventStatus::Reversed,
            'reversed_at' => now(),
            'blocked_reason' => $reason,
        ]);

        return $commissionEvent->fresh();
    }
}
