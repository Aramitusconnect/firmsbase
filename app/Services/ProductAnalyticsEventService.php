<?php

namespace App\Services;

use App\Enums\ProductAnalyticsEventType;
use App\Models\Firm;
use App\Models\ProductAnalyticsEvent;

/**
 * ProductAnalyticsEventService — the only writer of
 * product_analytics_events. record() only accepts a
 * ProductAnalyticsEventType enum instance, so an event type outside the
 * closed set cannot be recorded — PHP's type system rejects an
 * arbitrary string at the call site before this method body ever runs.
 */
class ProductAnalyticsEventService
{
    public function record(
        ProductAnalyticsEventType $eventType,
        ?Firm $firm = null,
        ?string $actorType = null,
        ?int $actorId = null,
        array $metadata = [],
    ): ProductAnalyticsEvent {
        return ProductAnalyticsEvent::create([
            'firm_id' => $firm?->id,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'event_type' => $eventType,
            'occurred_at' => now(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    public function countForFirm(Firm $firm, ProductAnalyticsEventType $eventType): int
    {
        return ProductAnalyticsEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', $eventType->value)
            ->count();
    }
}
