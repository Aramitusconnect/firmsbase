<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * PlatformAdminRecoveryCodeUsedNotification — Mission 1B (Extreme
 * Security Hardening), section 8: "Treat account recovery as
 * security-critical... user-notification." A recovery code is
 * one-time-use and only meant for the account's real owner — every
 * consumption is a signal worth surfacing to that owner even when
 * it's legitimate, since the alternative (silent, no notification) is
 * exactly how a stolen-recovery-code takeover goes unnoticed. Sent on
 * every successful use, not just suspicious ones — this mission's own
 * section 12 explicitly says not to build brittle automatic lockouts,
 * so this is the lightweight, always-on signal instead.
 */
class PlatformAdminRecoveryCodeUsedNotification extends Notification
{
    use Queueable;

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A recovery code was just used on your FirmsVault Admin account')
            ->line('A two-factor authentication recovery code was used to sign in to your Platform Admin account.')
            ->line('If this was you, no action is needed — but consider regenerating your recovery codes soon, since this one is now spent and cannot be reused.')
            ->line('If this was NOT you, your account may be compromised: change your password immediately, remove and re-enroll your MFA factors, and revoke your active sessions from the Platform Administrators page.');
    }
}
