<?php

namespace App\Enums;

/**
 * NotificationEventStatus — notification_events.status. Values taken
 * from your Phase 4 message verbatim: "attempted, blocked, queued,
 * sent, failed, bounced, complained, suppressed". Blocked covers both
 * an unverified sender/domain AND a failed NotificationEligibilityService
 * check (do_not_contact/no consent/suppressed) — the distinguishing
 * reason is recorded in notification_events.reason, not a further
 * split of this enum.
 */
enum NotificationEventStatus: string
{
    case Attempted = 'attempted';
    case Blocked = 'blocked';
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Suppressed = 'suppressed';
}
