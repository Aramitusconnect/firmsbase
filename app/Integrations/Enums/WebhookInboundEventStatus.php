<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * WebhookInboundEventStatus — lifecycle state of an
 * `integration_inbound_webhook_events` row (Checkpoint 7,
 * reviews/checkpoint-07/frozen-design-post-security-review.md §10.2).
 * Plain string column, no DB-level enum type. 5-state, mirrors
 * `OutboxEventStatus`'s exact convention (App\Integrations\Enums\OutboxEventStatus)
 * — this checkpoint ships only the row-creation path
 * (App\Integrations\Services\InboundWebhookEventService), which always
 * writes `Verified`; the remaining states exist as the specification
 * for the guarded single-statement claim/complete/fail SQL *shape* a
 * future Checkpoint 8 pooled-claim worker will use (frozen design §15
 * — explicitly deferred, not implemented as a pooled mechanism here).
 */
enum WebhookInboundEventStatus: string
{
    case Verified = 'verified';
    case HandedOff = 'handed_off';
    case Processed = 'processed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
