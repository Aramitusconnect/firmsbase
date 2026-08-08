<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * FirmSeatLimitExceededException — thrown by
 * `FirmUserInvitationService::invite()` when
 * `FirmSeatCapacityService::canInvite()` reports the firm has no
 * remaining licensed seats. Firm Feature Manifest §12's flat per-firm
 * seat model REPLACED the original per-`SeatClass` (Attorney/Staff/
 * ReadOnly) seat-exhaustion message this class used to carry — the
 * message below is deliberately flat and end-user-appropriate (no
 * per-class language, no raw exception internals), matching the
 * business model: every role consumes one identical seat, so there is
 * nothing class-specific left to say. The class NAME is kept unchanged
 * for call-site/catch-clause compatibility (`InviteFirmUserAction`,
 * this service's own tests) even though its shape changed from a
 * `SeatClass`-keyed constructor to a flat purchased/no-license
 * distinction.
 */
class FirmSeatLimitExceededException extends RuntimeException
{
    /**
     * @param  int|null  $purchasedSeats  the firm's purchased seat
     *                                    quantity, or null if the firm has no purchased-seat quantity
     *                                    configured at all (no license, or a license with no seats set).
     */
    public function __construct(?int $purchasedSeats)
    {
        parent::__construct($purchasedSeats === null
            ? 'This firm has no licensed user seats configured. Contact your administrator to set up seat licensing before inviting team members.'
            : "Your firm has used all {$purchasedSeats} licensed user seats. Contact your administrator to add more seats.");
    }
}
