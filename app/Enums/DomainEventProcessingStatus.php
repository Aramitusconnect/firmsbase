<?php

namespace App\Enums;

/**
 * DomainEventProcessingStatus — Event-Driven Automation Engine, item 9.
 * domain_events.processing_status — mirrors app/Integrations/Outbox's
 * own proven OutboxEventStatus shape (Pending/Processing/Completed/
 * Failed/DeadLettered), renamed to this table's own vocabulary
 * (Processed rather than Completed, to read naturally against "has this
 * event been processed for automation yet").
 */
enum DomainEventProcessingStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Processed = 'processed';
    case Failed = 'failed';
    case DeadLettered = 'dead_lettered';
}
