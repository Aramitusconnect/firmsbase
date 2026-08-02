<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Exceptions\NotificationTransportFailedException;
use App\Models\Firm;
use App\Models\NotificationProviderCorrelation;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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
 * Failure contract (post-9722e88 audit remediation):
 *   - BEFORE the send closure runs (creating the correlation row):
 *     any failure propagates as a plain exception. Nothing has been
 *     sent, so the caller must treat this as "do not send" — never a
 *     reason to fall back to an uncorrelated send.
 *   - DURING the send closure itself (the real ->notify() call):
 *     a failure is wrapped in NotificationTransportFailedException so
 *     the caller can distinguish "the transport itself failed" (no
 *     email went out) from a correlation-bookkeeping failure.
 *   - AFTER the send closure returns successfully (persisting the
 *     confirmed provider message id, recording the Sent event): a
 *     failure here is logged as a CRITICAL reconciliation incident
 *     (never rethrown) — SES has already accepted the message, so
 *     this method must never cause a second send, and must never
 *     silently report a fully-correlated success when the
 *     correlation itself is actually incomplete.
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

        // BEFORE-SEND: propagates as a plain exception on failure —
        // nothing has been sent yet. Wrapped in its own DB::transaction()
        // so a failure here can never poison a caller's own wrapping
        // transaction (Postgres aborts the entire current transaction
        // block on an uncommitted statement failure, not just the one
        // statement) — Laravel automatically uses a SAVEPOINT here when
        // already inside one, exactly like recordReceipt()'s own fix in
        // SesEventConsumerService.
        DB::transaction(function () use ($correlationId, $firm, $channel, $normalizedRecipient): void {
            NotificationProviderCorrelation::create([
                'correlation_id' => $correlationId,
                'firm_id' => $firm->id,
                'channel' => $channel->value,
                'recipient_normalized' => $normalizedRecipient,
            ]);
        });

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
            try {
                $send($correlationId);
            } catch (Throwable $e) {
                throw new NotificationTransportFailedException($e);
            }
        } finally {
            $this->events->forget(MessageSent::class);

            foreach ($previousListeners as $previousListener) {
                $this->events->listen(MessageSent::class, $previousListener);
            }
        }

        // POST-SEND: the transport call above did not throw — SES has
        // already accepted the message (or, at minimum, Laravel's mail
        // transport returned without error). Everything from here on
        // is reconciliation bookkeeping only; a failure must never
        // cause a second send and must never be silently swallowed as
        // if correlation were complete.
        if ($providerMessageId === null) {
            Log::critical('outbound_mail_correlation_sent_without_confirmed_provider_message_id', [
                'correlation_id' => $correlationId,
                'firm_id' => $firm->id,
                'channel' => $channel->value,
            ]);

            return;
        }

        try {
            // Deliberately TWO separate DB::transaction() calls, not
            // one: persisting provider_message_id is the critical piece
            // for future bounce/complaint reconciliation ("preserve
            // enough state to reconcile the SES message ID") and must
            // survive even if recordSent() itself then fails — wrapping
            // both in one transaction would roll the persisted
            // provider_message_id back too on a recordSent() failure,
            // losing exactly the state this is supposed to preserve.
            // Each is independently wrapped so a caught failure in
            // either can never poison a caller's own wrapping
            // transaction (e.g. FirmProvisioningService's own
            // $request->forceFill(...)->save() call immediately after
            // dispatchOwnerInvitation() returns).
            DB::transaction(function () use ($correlationId, $providerMessageId): void {
                NotificationProviderCorrelation::where('correlation_id', $correlationId)
                    ->update(['provider_message_id' => $providerMessageId]);
            });

            DB::transaction(function () use ($correlationId, $firm, $channel, $normalizedRecipient, $providerMessageId): void {
                $this->dispatchService->recordSent($firm, $correlationId, $channel, $normalizedRecipient, null, null, null, $providerMessageId);
            });
        } catch (Throwable $e) {
            Log::critical('outbound_mail_correlation_post_send_reconciliation_required', [
                'correlation_id' => $correlationId,
                'firm_id' => $firm->id,
                'channel' => $channel->value,
                'provider_message_id' => $providerMessageId,
                'exception' => $e::class,
            ]);
        }
    }

    private function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
