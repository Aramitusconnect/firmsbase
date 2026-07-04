<?php

namespace App\Services;

use App\Enums\PlatformSubscriptionStatus;
use App\Enums\SeatClass;
use App\Models\BillingAccount;
use App\Models\Plan;
use App\Models\PlatformSubscription;
use App\Models\PlatformSubscriptionItem;

/**
 * PlatformSubscriptionService — the only place platform_subscriptions
 * and platform_subscription_items rows are created. PLATFORM billing
 * only, keyed to billing_account_id — never a firm-client PaymentPlan
 * (project rule 1).
 */
class PlatformSubscriptionService
{
    public function subscribe(
        BillingAccount $billingAccount,
        Plan $plan,
        \DateTimeInterface $currentPeriodStartsAt,
        \DateTimeInterface $currentPeriodEndsAt,
        ?\DateTimeInterface $trialEndsAt = null,
    ): PlatformSubscription {
        return PlatformSubscription::create([
            'billing_account_id' => $billingAccount->id,
            'plan_id' => $plan->id,
            'status' => $trialEndsAt ? PlatformSubscriptionStatus::Trialing : PlatformSubscriptionStatus::Active,
            'billing_interval' => $plan->billing_interval,
            'current_period_starts_at' => $currentPeriodStartsAt,
            'current_period_ends_at' => $currentPeriodEndsAt,
            'trial_ends_at' => $trialEndsAt,
        ]);
    }

    public function cancel(PlatformSubscription $subscription, bool $atPeriodEnd = true): PlatformSubscription
    {
        if ($atPeriodEnd) {
            return tap($subscription)->update(['cancel_at_period_end' => true])->fresh();
        }

        return tap($subscription)->update([
            'status' => PlatformSubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ])->fresh();
    }

    public function addItem(
        PlatformSubscription $subscription,
        string $itemType,
        int $quantity,
        int $unitAmountCents,
        ?SeatClass $seatClass = null,
        array $metadata = [],
    ): PlatformSubscriptionItem {
        return PlatformSubscriptionItem::create([
            'platform_subscription_id' => $subscription->id,
            'item_type' => $itemType,
            'seat_class' => $seatClass,
            'quantity' => $quantity,
            'unit_amount_cents' => $unitAmountCents,
            'metadata' => $metadata,
        ]);
    }
}
