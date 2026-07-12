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
 *
 * Section 39A-3L, Checkpoint 24 — notification_events is now
 * FORCE-protected. recordBounce() and recordComplaint() each wrap
 * their own single NotificationEvent::create() call tightly in its own
 * runWithFirmContext($firm, ...) call. isSuppressed() (a read) needed
 * no change: its only live call chain (NotificationEligibilityService
 * ::check() -> DocumentChaseService::checkAndLog()) already wraps its
 * entire body in an outer runWithFirmContext() call, established at
 * Checkpoint 10 when document_chase_* was forced — wrapping
 * isSuppressed() itself here would nest an inner context inside that
 * still-active outer one, whose finally would clear the outer
 * caller's context prematurely. As of this checkpoint, recordBounce()
 * and recordComplaint() have no production caller at all (confirmed
 * via repository-wide search); this wiring is added now anyway so
 * neither becomes a landmine for whoever wires a live caller in next.
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
        return (new TenantContextService())->runWithFirmContext($firm, fn () => NotificationEvent::create([
            'firm_id' => $firm->id,
            'correlation_id' => $correlationId,
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => NotificationEventStatus::Bounced,
            'reason' => $reason,
        ]));
    }

    public function recordComplaint(Firm $firm, string $recipient, ConsentChannel $channel, string $correlationId, ?string $reason = null): NotificationEvent
    {
        return (new TenantContextService())->runWithFirmContext($firm, fn () => NotificationEvent::create([
            'firm_id' => $firm->id,
            'correlation_id' => $correlationId,
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => NotificationEventStatus::Complained,
            'reason' => $reason,
        ]));
    }
}
