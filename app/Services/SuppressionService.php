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
 *
 * Post-578ee98 audit finding B3: recordBounce()/recordComplaint() are
 * now the SES event consumer's live callers, and SesEventConsumerService's
 * OWN concurrency gate (the ses_event_receipts unique constraint) can
 * theoretically be raced past by two concurrent consumer processes
 * before either commits its receipt. firstOrCreate() on
 * [correlation_id, status] makes a duplicate/racing call here a safe
 * no-op (returns the existing row instead of inserting a second one) —
 * this table's own idempotency guard, independent of and in addition
 * to the receipt ledger (defense in depth). Keyed on status as well as
 * correlation_id because a single correlation legitimately could, in
 * principle, accumulate both a Bounced and a Complained row over time
 * (rare, but not a duplicate) — only a second call for the SAME status
 * is treated as a repeat.
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
        return (new TenantContextService)->runWithFirmContext($firm, fn () => NotificationEvent::query()->firstOrCreate(
            [
                'correlation_id' => $correlationId,
                'status' => NotificationEventStatus::Bounced,
            ],
            [
                'firm_id' => $firm->id,
                'channel' => $channel,
                'recipient' => $recipient,
                'reason' => $reason,
            ],
        ));
    }

    public function recordComplaint(Firm $firm, string $recipient, ConsentChannel $channel, string $correlationId, ?string $reason = null): NotificationEvent
    {
        return (new TenantContextService)->runWithFirmContext($firm, fn () => NotificationEvent::query()->firstOrCreate(
            [
                'correlation_id' => $correlationId,
                'status' => NotificationEventStatus::Complained,
            ],
            [
                'firm_id' => $firm->id,
                'channel' => $channel,
                'recipient' => $recipient,
                'reason' => $reason,
            ],
        ));
    }
}
