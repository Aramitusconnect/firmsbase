<?php

declare(strict_types=1);

namespace App\Marketplace\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * MarketplaceIntakeAcceptedNotification — Mission 3 (MyAttorney
 * Conversion + AI Intake), checkpoint 13. Sent to a prospect's own
 * MarketplaceIntake.prospect_email once a Firm accepts their intake
 * (MarketplaceIntakeService::markAccepted()) — no prior notification
 * of any kind existed anywhere in the Accept/Decline flow before this
 * checkpoint. Deliberately generic — no matter-specific, financial, or
 * legal-advice content (the mission's own "no AI/system may give legal
 * advice" boundary applies to every prospect-facing surface, not only
 * AI-generated text). Never queued (ShouldQueue is deliberately NOT
 * implemented) — OutboundMailCorrelationService's MessageSent listener
 * must observe the send synchronously within the same call to
 * correlate it, mirroring FirmOwnerInvitationNotification/
 * ClientPortalResetPasswordNotification's own established pattern.
 */
class MarketplaceIntakeAcceptedNotification extends Notification
{
    use Queueable;

    private ?string $correlationId = null;

    public function __construct(private readonly string $firmDisplayName) {}

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
            ->subject("{$this->firmDisplayName} would like to move forward with your request")
            ->line("Good news — {$this->firmDisplayName} has reviewed the information you submitted and would like to proceed toward a consultation.")
            ->line('The firm will be in touch with you directly to arrange next steps.')
            ->line('This message confirms receipt only and is not legal advice.');

        if ($this->correlationId !== null) {
            $message->metadata('correlation_id', $this->correlationId);
        }

        return $message;
    }
}
