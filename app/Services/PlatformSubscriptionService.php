<?php

namespace App\Services;

use App\Enums\PlatformSubscriptionStatus;
use App\Enums\SeatClass;
use App\Models\BillingAccount;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformSubscription;
use App\Models\PlatformSubscriptionItem;

/**
 * PlatformSubscriptionService — the only place platform_subscriptions
 * and platform_subscription_items rows are created. PLATFORM billing
 * only, keyed to billing_account_id — never a firm-client PaymentPlan
 * (project rule 1).
 *
 * Phase 3 (FirmsVault Platform Admin Control Center, "Billing and
 * Commercial Administration") addition: cancel() now accepts an
 * optional PlatformAdmin $actor and, when one is supplied, records a
 * PlatformAdminAuditEventRecorder::recordPlatformEvent() row (the
 * firm-less variant — a subscription is not tied to one firm). Mirrors
 * ManualPaymentService::submit(..., ?User $recordedBy, ...) and
 * PaymentPlanInstallmentService::markWaived(..., User $actor, ...) on
 * the firm-client side. When $actor is null (every existing caller —
 * no app-level call site currently passes one; only tests call cancel()
 * directly today) behavior is byte-for-byte unchanged from before this
 * addition: no audit row is written.
 */
class PlatformSubscriptionService
{
    private const AUDIT_CATEGORY = 'platform_billing';

    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

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

    public function cancel(PlatformSubscription $subscription, bool $atPeriodEnd = true, ?PlatformAdmin $actor = null): PlatformSubscription
    {
        if ($atPeriodEnd) {
            $cancelled = tap($subscription)->update(['cancel_at_period_end' => true])->fresh();
        } else {
            $cancelled = tap($subscription)->update([
                'status' => PlatformSubscriptionStatus::Cancelled,
                'cancelled_at' => now(),
            ])->fresh();
        }

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'subscription_cancelled',
                self::AUDIT_CATEGORY,
                [
                    'platform_subscription_id' => $cancelled->id,
                    'billing_account_id' => $cancelled->billing_account_id,
                    'at_period_end' => $atPeriodEnd,
                    'resulting_status' => $cancelled->status->value,
                ],
            );
        }

        return $cancelled;
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
