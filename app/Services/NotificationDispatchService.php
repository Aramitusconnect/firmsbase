<?php

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Enums\NotificationEventStatus;
use App\Jobs\DispatchNotificationJob;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\NotificationEvent;
use App\ValueObjects\NotificationDispatchResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * NotificationDispatchService — the ONLY place notification_events
 * rows are created for an outbound send attempt (SuppressionService
 * additionally logs inbound bounce/complaint events). No real email is
 * ever sent — DispatchNotificationJob is a fakeable dispatch
 * abstraction, never a real mail transport call (project rule).
 *
 * Gate order, all before anything is queued:
 *   1. An active template must resolve for (key, channel, language).
 *   2. The template's sender/domain must be verified (project rule:
 *      "must not be sent from unverified sender domains") — no live
 *      DNS lookup, reads stored NotificationTemplate fields only.
 *   3. NotificationEligibilityService must return eligible (consent,
 *      do_not_contact, suppression).
 * Every attempt — accepted or blocked — writes an Attempted event
 * first, then exactly one more event recording the outcome.
 *
 * Section 39A-3L, Checkpoint 24 — notification_events is now
 * FORCE-protected. dispatch() wraps its entire body in a single
 * runWithFirmContext($firm, ...) call: it receives $firm directly, and
 * none of its three collaborators — NotificationTemplateService::resolve(),
 * SenderDomainVerificationService::isVerified(), and
 * NotificationEligibilityService::check() — self-wrap in
 * runWithFirmContext() (NotificationEligibilityService::check() reads
 * FORCE-RLS-protected tables communication_consents and
 * client_communication_preferences, forced since Checkpoint 11, and
 * depends on this ambient context being active). This matches the
 * established default pattern (see PaymentPlanService::create()): wrap
 * the whole call at the site that owns $firm, rather than wrapping each
 * inner call independently. The four recordEvent() calls inside
 * dispatch() are plain sequential calls — they rely on the single
 * active outer context and never open their own nested wrap, since a
 * nested wrap's finally would clear the outer context prematurely.
 * recordSent() and recordFailed() each wrap their own single
 * NotificationEvent::create() call — this is sufficient for
 * DispatchNotificationJob::handle() (a queued job with no ambient
 * context of its own) since recordSent() is its only
 * notification_events touch point; the job itself needed no separate
 * wrap. As of this checkpoint, dispatch() has no production caller
 * (confirmed via repository-wide search) and recordFailed() has no
 * caller at all, including from within dispatch() itself — this wiring
 * is added now anyway so neither becomes a landmine for whoever wires
 * a live caller in next.
 */
class NotificationDispatchService
{
    public function __construct(
        private NotificationTemplateService $templates,
        private SenderDomainVerificationService $domainVerification,
        private NotificationEligibilityService $eligibility,
    ) {
    }

    public function dispatch(
        Firm $firm,
        Client $client,
        ConsentChannel $channel,
        string $recipient,
        string $templateKey,
        string $language = 'en',
        ?Model $subject = null,
        ?Matter $matter = null,
    ): NotificationDispatchResult {
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $client, $channel, $recipient, $templateKey, $language, $subject, $matter) {
            $correlationId = (string) Str::uuid();

            $this->recordEvent($firm, $correlationId, $channel, $recipient, NotificationEventStatus::Attempted, null, $client, $matter, null, $subject);

            $template = $this->templates->resolve($firm, $templateKey, $channel, $language);

            if (! $template) {
                $reason = "no active notification template found for key={$templateKey} channel={$channel->value} language={$language}";
                $this->recordEvent($firm, $correlationId, $channel, $recipient, NotificationEventStatus::Blocked, $reason, $client, $matter, null, $subject);

                return new NotificationDispatchResult(NotificationEventStatus::Blocked, false, $reason);
            }

            if (! $this->domainVerification->isVerified($template)) {
                $reason = 'sender domain not verified: '.($template->from_domain ?? 'unknown domain');
                $this->recordEvent($firm, $correlationId, $channel, $recipient, NotificationEventStatus::Blocked, $reason, $client, $matter, $template->id, $subject);

                return new NotificationDispatchResult(NotificationEventStatus::Blocked, false, $reason);
            }

            $eligibility = $this->eligibility->check($firm, $client, $channel, $recipient);

            if (! $eligibility->eligible) {
                $status = str_contains((string) $eligibility->reason, 'suppressed')
                    ? NotificationEventStatus::Suppressed
                    : NotificationEventStatus::Blocked;

                $this->recordEvent($firm, $correlationId, $channel, $recipient, $status, $eligibility->reason, $client, $matter, $template->id, $subject);

                return new NotificationDispatchResult($status, false, $eligibility->reason);
            }

            $this->recordEvent($firm, $correlationId, $channel, $recipient, NotificationEventStatus::Queued, null, $client, $matter, $template->id, $subject);

            DispatchNotificationJob::dispatch($firm->id, $correlationId, $template->id, $channel->value, $recipient, $client->id, $matter?->id);

            return new NotificationDispatchResult(NotificationEventStatus::Queued, true);
        });
    }

    /**
     * Called by DispatchNotificationJob once it "sends" (never a real
     * transport call).
     */
    public function recordSent(Firm $firm, string $correlationId, ConsentChannel $channel, string $recipient, ?int $templateId, ?int $clientId, ?int $matterId): NotificationEvent
    {
        return (new TenantContextService())->runWithFirmContext($firm, fn () => NotificationEvent::create([
            'firm_id' => $firm->id,
            'notification_template_id' => $templateId,
            'client_id' => $clientId,
            'matter_id' => $matterId,
            'correlation_id' => $correlationId,
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => NotificationEventStatus::Sent,
        ]));
    }

    public function recordFailed(Firm $firm, string $correlationId, ConsentChannel $channel, string $recipient, ?int $templateId, string $reason): NotificationEvent
    {
        return (new TenantContextService())->runWithFirmContext($firm, fn () => NotificationEvent::create([
            'firm_id' => $firm->id,
            'notification_template_id' => $templateId,
            'correlation_id' => $correlationId,
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => NotificationEventStatus::Failed,
            'reason' => $reason,
        ]));
    }

    private function recordEvent(
        Firm $firm,
        string $correlationId,
        ConsentChannel $channel,
        string $recipient,
        NotificationEventStatus $status,
        ?string $reason,
        Client $client,
        ?Matter $matter,
        ?int $templateId,
        ?Model $subject,
    ): NotificationEvent {
        return NotificationEvent::create([
            'firm_id' => $firm->id,
            'notification_template_id' => $templateId,
            'client_id' => $client->id,
            'matter_id' => $matter?->id,
            'correlation_id' => $correlationId,
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => $status,
            'reason' => $reason,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);
    }
}
