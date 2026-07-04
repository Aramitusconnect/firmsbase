<?php

namespace App\Services;

use App\Models\BillingAccount;
use App\Models\PlatformBillingEvent;

/**
 * PlatformBillingEventService — the only place platform_billing_events
 * rows are created. event_type is a plain string (project convention).
 */
class PlatformBillingEventService
{
    public function log(BillingAccount $billingAccount, string $eventType, array $metadata = []): PlatformBillingEvent
    {
        return PlatformBillingEvent::create([
            'billing_account_id' => $billingAccount->id,
            'event_type' => $eventType,
            'metadata' => $metadata,
        ]);
    }
}
