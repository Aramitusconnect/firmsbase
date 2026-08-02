<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * CorrelatedSendResult — post-9722e88 audit remediation. The typed
 * result CorrelatedPasswordResetSenderService::send() always returns,
 * never throws. User::sendPasswordResetNotification()/
 * ClientPortalUser::sendPasswordResetNotification() discard this value
 * (Laravel's CanResetPassword contract is void, and the public,
 * anti-enumeration-sensitive forgot-password flow must never behave
 * differently based on it); FirmProvisioningService inspects it
 * directly via sendResetLink()'s own $callback parameter.
 */
enum CorrelatedSendResult
{
    /**
     * The notification was handed to the mail transport successfully,
     * whether via firm-scope or platform-scope correlation.
     */
    case Sent;

    /**
     * The recipient is platform-suppressed (a prior permanent bounce/
     * complaint) — no send was attempted at all.
     */
    case Suppressed;

    /**
     * A failure occurred BEFORE the real send was attempted (creating
     * the correlation row, HMAC fingerprinting, the platform
     * suppression check itself, or required configuration). No email
     * was sent. Never falls back to an uncorrelated send.
     */
    case CorrelationFailed;

    /**
     * The real send itself (the mail transport call) failed. No email
     * was sent.
     */
    case TransportFailed;

    public function wasSent(): bool
    {
        return $this === self::Sent;
    }
}
