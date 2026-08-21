<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ClientPortalInvitationNotification — Mission 3A (MyAttorney
 * Launch-Flow Closure). Sent to Client::email once
 * ClientPortalService::invite() marks a client Invited — closing the
 * pre-existing, mission-wide-confirmed gap that no accept-invitation
 * email was ever sent for ANY client, MyAttorney-converted or not
 * (flagged in Mission 3 checkpoint 13, confirmed still open in this
 * mission's own closure audit).
 *
 * Deliberately plain — Client is not a Notifiable model (its own
 * ClientPortalUser credential shell does not exist yet at invite
 * time, by design: see ClientPortalService's own "Client !=
 * ClientPortalUser" contract), so this is sent via Laravel's
 * on-demand routing (Notification::route('mail', ...)), mirroring
 * MarketplaceProspectNotificationService's own established pattern
 * for exactly this situation — never queued (ShouldQueue is
 * deliberately NOT implemented), so OutboundMailCorrelationService's
 * MessageSent listener can observe the send synchronously within the
 * same call to correlate it.
 */
class ClientPortalInvitationNotification extends Notification
{
    use Queueable;

    private ?string $correlationId = null;

    public function __construct(
        private readonly string $firmDisplayName,
        private readonly string $invitationUrl,
    ) {}

    public function withCorrelationId(string $correlationId): static
    {
        $this->correlationId = $correlationId;

        return $this;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("You've been invited to {$this->firmDisplayName}'s secure client portal")
            ->line("{$this->firmDisplayName} has invited you to their secure online client portal, where you can view your matter.")
            ->action('Set Up Your Portal Access', $this->invitationUrl)
            ->line('This link is unique to you and will expire — if it has expired, please contact the firm directly for a new one.')
            ->line('If you were not expecting this invitation, you can safely ignore this email.');

        if ($this->correlationId !== null) {
            $message->metadata('correlation_id', $this->correlationId);
        }

        return $message;
    }
}
