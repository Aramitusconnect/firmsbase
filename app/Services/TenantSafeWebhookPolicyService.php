<?php

namespace App\Services;

use App\Exceptions\TenantIsolationException;
use App\Models\Firm;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Models\WebhookSecret;
use App\Models\WebhookSubscription;

/**
 * TenantSafeWebhookPolicyService — mirrors TenantSafeTrustPolicyService's
 * exact pattern (defense in depth, independent of and in addition to
 * BelongsToTenant's global scope where that trait is applied).
 */
class TenantSafeWebhookPolicyService
{
    public function assertWebhookSubscriptionBelongsToFirm(WebhookSubscription $subscription, Firm $firm): void
    {
        if ($subscription->firm_id !== $firm->id) {
            throw new TenantIsolationException("WebhookSubscription [id={$subscription->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertWebhookEventBelongsToFirm(WebhookEvent $event, Firm $firm): void
    {
        if ($event->firm_id !== $firm->id) {
            throw new TenantIsolationException("WebhookEvent [id={$event->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertWebhookDeliveryBelongsToFirm(WebhookDelivery $delivery, Firm $firm): void
    {
        if ($delivery->firm_id !== $firm->id) {
            throw new TenantIsolationException("WebhookDelivery [id={$delivery->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertWebhookSecretBelongsToFirm(WebhookSecret $secret, Firm $firm): void
    {
        if ($secret->firm_id !== $firm->id) {
            throw new TenantIsolationException("WebhookSecret [id={$secret->id}] does not belong to firm [id={$firm->id}].");
        }
    }
}
