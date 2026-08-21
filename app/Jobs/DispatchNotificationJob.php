<?php

namespace App\Jobs;

use App\Enums\ConsentChannel;
use App\Models\Firm;
use App\Models\NotificationTemplate;
use App\Notifications\TemplatedNotification;
use App\Services\NotificationDispatchService;
use App\Services\OutboundMailCorrelationService;
use App\Services\TenantContextService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * DispatchNotificationJob — queued only after NotificationDispatchService
 * ::dispatch() has already passed template resolution, sender/domain
 * verification, and eligibility, and has recorded a Queued event.
 *
 * Mission 6 (Real Communications & Notification Delivery): this job now
 * reaches Laravel's real Notification/Mail system for the email
 * channel, reusing the SAME correlated-send infrastructure
 * (OutboundMailCorrelationService::correlate()) that
 * ClientPortalInvitationNotificationService and
 * CorrelatedPasswordResetSenderService already use in production —
 * never a second, parallel mail pathway. correlate() creates a
 * NotificationProviderCorrelation row, runs the real ->notify() call
 * inside a scoped MessageSent listener, and only calls
 * NotificationDispatchService::recordSent() itself once Laravel's mail
 * transport confirms a message id. Whether that message id is a real
 * SES id or stays unconfirmed (e.g. a 'log'/'array' mailer in a
 * non-production environment) is entirely decided by the app's
 * existing mail configuration — this job does not special-case any
 * environment.
 *
 * Known fidelity limit inherited from OutboundMailCorrelationService
 * (not introduced here, and out of scope to change per this mission's
 * file-ownership boundary): correlate() always mints its own fresh
 * correlation id for the NotificationProviderCorrelation row/eventual
 * Sent event, and always calls recordSent() with
 * templateId=null/clientId=null/matterId=null. That means the eventual
 * "Sent" notification_events row is not linked back to this job's own
 * $correlationId (the one the "Attempted"/"Queued" rows carry) or to
 * this job's $templateId/$clientId/$matterId — exactly the same
 * limitation ClientPortalInvitationNotificationService already lives
 * with today.
 *
 * Non-email channels (sms/whatsapp/portal): no real transport exists
 * for these (SMS/WhatsApp provider integration is explicitly out of
 * scope per this mission — "6.7, DO NOT IMPLEMENT"). For those
 * channels this job preserves its previous fakeable-boundary behavior
 * (recordSent() with providerMessageId=null) rather than attempting a
 * send that has nowhere real to go.
 */
class DispatchNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $firmId,
        public string $correlationId,
        public int $templateId,
        public string $channel,
        public string $recipient,
        public int $clientId,
        public ?int $matterId,
    ) {}

    public function handle(NotificationDispatchService $dispatcher, OutboundMailCorrelationService $mailCorrelation): void
    {
        $firm = Firm::query()->find($this->firmId);

        if (! $firm) {
            return;
        }

        $channel = ConsentChannel::from($this->channel);

        $template = app(TenantContextService::class)->runWithFirmContext(
            $firm,
            fn () => NotificationTemplate::query()->find($this->templateId),
        );

        if (! $template) {
            Log::warning('dispatch_notification_job_template_missing_at_send_time', [
                'firm_id' => $this->firmId,
                'notification_template_id' => $this->templateId,
                'correlation_id' => $this->correlationId,
            ]);

            // null, not $this->templateId: notification_template_id has
            // a real foreign key constraint, and this branch means the
            // row genuinely does not exist (or no longer exists) — a
            // dangling id would violate that constraint.
            $dispatcher->recordFailed($firm, $this->correlationId, $channel, $this->recipient, null, 'template no longer resolvable at send time');

            return;
        }

        if ($channel !== ConsentChannel::Email) {
            // No real transport exists yet for this channel — see class
            // docblock. Preserve the previous fakeable-boundary
            // behavior so the attempt is still faithfully recorded
            // without claiming a real send that cannot happen.
            $dispatcher->recordSent(
                $firm,
                $this->correlationId,
                $channel,
                $this->recipient,
                $this->templateId,
                $this->clientId,
                $this->matterId,
            );

            return;
        }

        try {
            $mailCorrelation->correlate(
                $firm,
                $channel,
                $this->recipient,
                fn (string $correlationId) => Notification::route('mail', $this->recipient)->notify(
                    (new TemplatedNotification($template->subject, $template->body))->withCorrelationId($correlationId)
                ),
            );
        } catch (Throwable $e) {
            report($e);

            Log::warning('dispatch_notification_job_send_failed', [
                'firm_id' => $this->firmId,
                'notification_template_id' => $this->templateId,
                'correlation_id' => $this->correlationId,
                'exception' => $e::class,
            ]);

            $dispatcher->recordFailed($firm, $this->correlationId, $channel, $this->recipient, $this->templateId, 'transport failed: '.$e::class);
        }
    }
}
