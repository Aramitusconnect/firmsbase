<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\SeatClass;
use RuntimeException;

/**
 * FirmSeatLimitExceededException — thrown by
 * `FirmUserInvitationService::invite()` when
 * `SeatEnforcementService::canInvite()` reports no remaining seats for
 * the invited role's `SeatClass`. Mirrors `SeatAllocationService::
 * allocateFromPool()`'s own "block the invite with a clear
 * pool-exhausted message" ruling (Firm Feature Manifest §12 / seat
 * enforcement's own docblock) — the invite is refused cleanly, never
 * silently ignored or allowed to over-allocate.
 */
class FirmSeatLimitExceededException extends RuntimeException
{
    public function __construct(SeatClass $seatClass)
    {
        parent::__construct(
            "This firm has no remaining '{$seatClass->value}' seats available. Free up a seat or increase the firm's seat allocation before inviting another team member."
        );
    }
}
