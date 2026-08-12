<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Enums\ConsentChannel;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Notifications\MarketplaceIntakeAcceptedNotification;
use App\Marketplace\Notifications\MarketplaceIntakeDeclinedNotification;
use App\Models\Firm;
use App\Services\OutboundMailCorrelationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * MarketplaceProspectNotificationService — Mission 3 (MyAttorney
 * Conversion + AI Intake), checkpoint 13. The only place a prospect's
 * plain MarketplaceIntake.prospect_email (not yet a Client, not yet a
 * ClientPortalUser — no Notifiable model exists for a bare intake row)
 * receives outbound communication. Reuses OutboundMailCorrelationService
 * directly with Laravel's on-demand notification routing
 * (Notification::route('mail', ...)) rather than
 * CorrelatedPasswordResetSenderService, which requires an
 * Eloquent Model with a real ->notify() method — deliberately a
 * DIFFERENT semantic category from that service's own "password reset/
 * invitation" scope (its own docblock), not a misuse of it.
 *
 * Never throws: every failure is caught and logged, exactly like
 * DocumentSecurityService::upload()'s own webhook-emission pattern —
 * a transactional email failing to send must never be a reason to
 * fail (or appear to fail) the Firm's own Accept/Decline decision,
 * which has already been durably recorded by the time these methods
 * are called (see MarketplaceIntakeService::markAccepted()/
 * markDeclined(), both of which call these from inside a
 * DB::afterCommit() closure).
 */
class MarketplaceProspectNotificationService
{
    public function __construct(private readonly OutboundMailCorrelationService $mailCorrelation) {}

    public function notifyAccepted(Firm $firm, MarketplaceIntake $intake): void
    {
        $this->send($firm, $intake, fn (string $correlationId) => (new MarketplaceIntakeAcceptedNotification($this->firmDisplayName($firm)))
            ->withCorrelationId($correlationId));
    }

    public function notifyDeclined(Firm $firm, MarketplaceIntake $intake): void
    {
        $this->send($firm, $intake, fn (string $correlationId) => (new MarketplaceIntakeDeclinedNotification($this->firmDisplayName($firm)))
            ->withCorrelationId($correlationId));
    }

    private function send(Firm $firm, MarketplaceIntake $intake, \Closure $makeNotification): void
    {
        $recipient = $intake->prospect_email;

        if (! is_string($recipient) || trim($recipient) === '') {
            return;
        }

        try {
            $this->mailCorrelation->correlate(
                $firm,
                ConsentChannel::Email,
                $recipient,
                fn (string $correlationId) => Notification::route('mail', $recipient)->notify($makeNotification($correlationId)),
            );
        } catch (Throwable $e) {
            report($e);

            Log::warning('marketplace_prospect_notification_failed', [
                'firm_id' => $firm->id,
                'marketplace_intake_id' => $intake->id,
                'exception' => $e::class,
            ]);
        }
    }

    private function firmDisplayName(Firm $firm): string
    {
        return $firm->firmSettings?->branding_settings_json['display_name_override']
            ?? $firm->legal_name
            ?? $firm->name;
    }
}
