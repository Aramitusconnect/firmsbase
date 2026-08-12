<?php

declare(strict_types=1);

namespace App\Marketplace\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * MarketplaceIntakeDeclinedNotification — Mission 3 (MyAttorney
 * Conversion + AI Intake), checkpoint 13. Sent to a prospect's own
 * MarketplaceIntake.prospect_email once a Firm declines their intake
 * (MarketplaceIntakeService::markDeclined()). Deliberately NEVER
 * includes MarketplaceIntake.decline_reason — that field is an
 * internal Firm-facing note (may reference a conflict of interest or
 * other confidential internal reasoning never meant for the prospect)
 * — mirrors the same "internal reason stays internal" boundary this
 * codebase already applies elsewhere (e.g. conflict-check possible
 * matches are never named to a requester). Never queued — see
 * MarketplaceIntakeAcceptedNotification's own docblock for why.
 */
class MarketplaceIntakeDeclinedNotification extends Notification
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
            ->subject("An update on your request to {$this->firmDisplayName}")
            ->line("Thank you for your interest — {$this->firmDisplayName} has reviewed your request and is unable to assist at this time.")
            ->line('This does not reflect on the merits of your matter — firms decline requests for many reasons, including scheduling and practice-area fit.')
            ->line('You are welcome to reach out to another firm.');

        if ($this->correlationId !== null) {
            $message->metadata('correlation_id', $this->correlationId);
        }

        return $message;
    }
}
