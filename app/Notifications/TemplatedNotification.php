<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * TemplatedNotification — Mission 6 (Real Communications & Notification
 * Delivery). The generic mail notification DispatchNotificationJob
 * sends for any template-driven dispatch (Send Invoice, dunning,
 * document-request-chase reminders, and any future
 * NotificationDispatchService::dispatch() caller) — renders a resolved
 * NotificationTemplate's subject/body verbatim into a MailMessage.
 *
 * Deliberately plain, mirroring ClientPortalInvitationNotification and
 * MarketplaceProspectNotificationService's own established pattern:
 * the recipient here is a raw email address (not always a Notifiable
 * model — Client has no ->notify() method by design), so this is sent
 * via Laravel's on-demand routing (Notification::route('mail', ...)).
 * Never queued (ShouldQueue is deliberately NOT implemented) so
 * OutboundMailCorrelationService's MessageSent listener can observe
 * the send synchronously within the same call to correlate it.
 */
class TemplatedNotification extends Notification
{
    use Queueable;

    private ?string $correlationId = null;

    public function __construct(
        private readonly ?string $subject,
        private readonly string $body,
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
        $message = (new MailMessage)->line($this->body);

        if ($this->subject !== null && trim($this->subject) !== '') {
            $message->subject($this->subject);
        }

        if ($this->correlationId !== null) {
            $message->metadata('correlation_id', $this->correlationId);
        }

        return $message;
    }
}
