<?php

namespace App\Services;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Models\WebhookSubscription;

/**
 * WebhookDeliveryService — enqueue() creates one Pending
 * webhook_deliveries row for a matching subscription. Never calls a
 * transport itself — that is WebhookDispatchJob's job, via
 * WebhookTransportInterface.
 */
class WebhookDeliveryService
{
    public function enqueue(WebhookEvent $event, WebhookSubscription $subscription): WebhookDelivery
    {
        return WebhookDelivery::create([
            'firm_id' => $event->firm_id,
            'webhook_subscription_id' => $subscription->id,
            'webhook_event_id' => $event->id,
            'status' => WebhookDeliveryStatus::Pending,
            'attempt_count' => 0,
        ]);
    }
}
