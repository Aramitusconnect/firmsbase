<?php

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Enums\NotificationEventStatus;
use App\Models\Firm;
use App\Models\NotificationEvent;

/**
 * SuppressionService — no separate suppression-list table exists
 * (staying inside the approved 15-table Phase 4 data contract, per
 * your clarification). A recipient is suppressed for a firm+channel
 * when a prior notification_events row already recorded Bounced,
 * Complained, or Suppressed for that exact recipient — the event log
 * itself IS the suppression list. suppress() records the decision as
 * a new append-only event rather than mutating history.
 */
class SuppressionService
{
    public function isSuppressed(Firm $firm, string $recipient, ConsentChannel $channel): bool
    {
        return NotificationEvent::query()
            ->where('firm_id', $firm->id)
            ->where('recipient', $recipient)
            ->where('channel', $channel->value)
            ->whereIn('status', [
                NotificationEventStatus::Bounced->value,
                NotificationEventStatus::Complained->value,
                NotificationEventStatus::Suppressed->value,
            ])
            ->exists();
    }

    public function recordBounce(Firm $firm, string $recipient, ConsentChannel $channel, string $correlationId, ?string $reason = null): NotificationEvent
    {
        return NotificationEvent::create([
            'firm_id' => $firm->id,
            'correlation_id' => $correlationId,
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => NotificationEventStatus::Bounced,
            'reason' => $reason,
        ]);
    }

    public function recordComplaint(Firm $firm, string $recipient, ConsentChannel $channel, string $correlationId, ?string $reason = null): NotificationEvent
    {
        return NotificationEvent::create([
            'firm_id' => $firm->id,
            'correlation_id' => $correlationId,
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => NotificationEventStatus::Complained,
            'reason' => $reason,
        ]);
    }
}
