<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * SyncTriggerSource — WHAT EVENT caused a `integration_sync_runs` row
 * to be created (Checkpoint 6, orthogonal to SyncRunType;
 * agent-6e-sync-run-item-cursor-semantics.md §5.2).
 *
 * `Webhook` is non-null-correlated to a specific triggering row only
 * via this enum case, not a direct FK — `integration_sync_runs.
 * triggering_webhook_event_id` is deliberately omitted at Checkpoint 6
 * (frozen-design-post-review.md §9: the referenced
 * integration_inbound_webhook_events table does not exist until
 * Checkpoint 7). This enum records WHY without needing a pointer to
 * the specific triggering row.
 */
enum SyncTriggerSource: string
{
    case Connect = 'connect';
    case Manual = 'manual';
    case SchedulerPoller = 'scheduler_poller';
    case Webhook = 'webhook';
    case CursorRepairAutoFire = 'cursor_repair_auto_fire';
    case RetryPoller = 'retry_poller';
}
