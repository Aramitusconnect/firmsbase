<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Models\Client;
use App\Notifications\ClientPortalInvitationNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * ClientPortalInvitationNotificationService — Mission 3A (MyAttorney
 * Launch-Flow Closure). The only place a Client Portal invitation
 * email is sent, reusing OutboundMailCorrelationService directly with
 * Laravel's on-demand notification routing, exactly like
 * MarketplaceProspectNotificationService's own established pattern
 * for a non-Notifiable recipient — Client has no ->notify() method by
 * design (see ClientPortalService's own "Client != ClientPortalUser"
 * contract; a real Notifiable credential shell only exists once
 * ClientPortalUser is created at activation time, not invitation
 * time).
 *
 * Never throws: every failure is caught and logged — a transactional
 * email failing to send must never fail (or appear to fail) the
 * Firm's own invite action, which has already been durably recorded
 * by the time this is called.
 */
class ClientPortalInvitationNotificationService
{
    public function __construct(private readonly OutboundMailCorrelationService $mailCorrelation) {}

    public function notifyInvited(Client $client, string $invitationUrl): void
    {
        $recipient = $client->email;

        if (! is_string($recipient) || trim($recipient) === '') {
            return;
        }

        $firm = $client->firm;
        $firmDisplayName = $firm->firmSettings?->branding_settings_json['display_name_override']
            ?? $firm->legal_name
            ?? $firm->name;

        try {
            $this->mailCorrelation->correlate(
                $firm,
                ConsentChannel::Portal,
                $recipient,
                fn (string $correlationId) => Notification::route('mail', $recipient)->notify(
                    (new ClientPortalInvitationNotification($firmDisplayName, $invitationUrl))->withCorrelationId($correlationId)
                ),
            );
        } catch (Throwable $e) {
            report($e);

            Log::warning('client_portal_invitation_notification_failed', [
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'exception' => $e::class,
            ]);
        }
    }
}
