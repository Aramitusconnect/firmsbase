<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Models\Firm;
use App\Models\NotificationProviderCorrelation;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OutboundMailCorrelationService — the outbound half of the SES bounce/
 * complaint consumer feature. Wires a real transactional send (today:
 * FirmOwnerInvitationNotification, ClientPortalResetPasswordNotification
 * — both currently bypass NotificationDispatchService entirely) to a
 * durable, tenant-scoped correlation record BEFORE the send, and to a
 * real notification_events "sent" row only AFTER Laravel's mail
 * transport confirms success.
 *
 * Sequencing (mission requirement: never record a fake Sent event
 * before SES accepts the message):
 *   1. correlate() creates the NotificationProviderCorrelation row
 *      (firm/channel/recipient only — no content) and returns an
 *      opaque UUID.
 *   2. The caller must tag its Notification with that id (see
 *      FirmOwnerInvitationNotification::withCorrelationId()), which
 *      Laravel's MailMessage::metadata() + SesTransport together turn
 *      into a real SES message Tag.
 *   3. The caller's own $send closure actually calls ->notify(...).
 *   4. A MessageSent listener — registered only for the duration of
 *      this one call — fires only if Laravel's mail transport returns
 *      successfully, and only reacts to a message whose own
 *      X-Metadata-correlation_id header matches this exact correlation
 *      id (never assumes it's the only mail in flight).
 *   5. Only once that fires do we persist the confirmed SES message id
 *      and call NotificationDispatchService::recordSent() — the first
 *      live production caller of that previously-dormant method.
 *
 * If the send throws, no listener ever fires, no "sent" row is ever
 * written, and the exception propagates unchanged to the caller's own
 * existing error handling (e.g. FirmProvisioningService::
 * dispatchOwnerInvitation()'s own try/catch and structured logging).
 *
 * Listener lifecycle (post-audit fix): the MessageSent listener
 * registered below is scoped to exactly one correlate() call. Laravel's
 * event dispatcher has no "remove this one closure" primitive, so this
 * uses the save/restore pattern instead: snapshot whatever MessageSent
 * listeners already exist, register ours, then in a finally block
 * (guaranteed to run whether $send() returns or throws) call
 * Dispatcher::forget() to clear ALL MessageSent listeners and
 * re-register exactly the ones that were there before. This guarantees
 * zero net listener growth per call — a hard requirement for a
 * long-running SQS/queue-worker process that may call correlate()
 * thousands of times without ever restarting.
 */
class OutboundMailCorrelationService
{
    public function __construct(
        private readonly NotificationDispatchService $dispatchService,
        private readonly Dispatcher $events,
    ) {}

    public function correlate(Firm $firm, ConsentChannel $channel, string $recipient, \Closure $send): void
    {
        $correlationId = (string) Str::uuid();
        $normalizedRecipient = $this->normalize($recipient);

        NotificationProviderCorrelation::create([
            'correlation_id' => $correlationId,
            'firm_id' => $firm->id,
            'channel' => $channel->value,
            'recipient_normalized' => $normalizedRecipient,
        ]);

        $providerMessageId = null;

        $listener = function (MessageSent $event) use ($correlationId, &$providerMessageId): void {
            $tagHeader = $event->message->getHeaders()->get('X-Metadata-correlation_id');

            if ($tagHeader === null || $tagHeader->getBodyAsString() !== $correlationId) {
                return;
            }

            $idHeader = $event->message->getHeaders()->get('X-Message-ID');
            $providerMessageId = $idHeader?->getBodyAsString();
        };

        $previousListeners = $this->events->getRawListeners()[MessageSent::class] ?? [];

        $this->events->listen(MessageSent::class, $listener);

        try {
            $send($correlationId);
        } finally {
            $this->events->forget(MessageSent::class);

            foreach ($previousListeners as $previousListener) {
                $this->events->listen(MessageSent::class, $previousListener);
            }
        }

        if ($providerMessageId === null) {
            Log::warning('outbound_mail_correlation_no_provider_message_id', [
                'correlation_id' => $correlationId,
                'firm_id' => $firm->id,
                'channel' => $channel->value,
            ]);

            return;
        }

        NotificationProviderCorrelation::where('correlation_id', $correlationId)
            ->update(['provider_message_id' => $providerMessageId]);

        $this->dispatchService->recordSent($firm, $correlationId, $channel, $normalizedRecipient, null, null, null, $providerMessageId);
    }

    private function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
