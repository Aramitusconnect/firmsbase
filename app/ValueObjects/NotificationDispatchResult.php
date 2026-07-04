<?php

namespace App\ValueObjects;

use App\Enums\NotificationEventStatus;

/**
 * NotificationDispatchResult — the outcome of
 * NotificationDispatchService::dispatch(). accepted is true only when
 * status is Queued or Sent; every Blocked/Failed/Suppressed outcome is
 * accepted = false.
 */
final readonly class NotificationDispatchResult
{
    public function __construct(
        public NotificationEventStatus $status,
        public bool $accepted,
        public ?string $reason = null,
    ) {
    }
}
