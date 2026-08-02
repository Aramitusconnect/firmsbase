<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SesBounceType;
use App\Enums\SesEventType;
use App\Models\Firm;
use App\Models\NotificationProviderCorrelation;
use App\Models\SesEventReceipt;
use Illuminate\Support\Facades\Log;

/**
 * SesEventConsumerService — parses and processes exactly one SQS
 * message body from the SES bounce/complaint event queue. Deliberately
 * separate from ConsumeSesEventsCommand (the polling loop / SQS
 * client interaction) — this class knows nothing about SQS itself,
 * only about the message body string and the SQS message id (used
 * only for diagnostics, never for any business decision).
 *
 * Return contract: process() returns true only when the message is
 * SAFE TO DELETE from the queue — either because it was durably,
 * successfully processed, or because it is a recognized non-event
 * (an SNS SubscriptionConfirmation) or a confirmed duplicate of an
 * already-processed event. It returns false for anything malformed,
 * unsupported, unresolved, or otherwise not safely actionable — the
 * caller must NOT delete the message in that case, letting SQS's own
 * redelivery/redrive-to-DLQ policy take over. This is the mission's
 * explicit "must not be acknowledged silently" rule, enforced at the
 * single choke point every caller goes through.
 *
 * Firm resolution NEVER uses the event's own recipient address alone
 * (project rule — the same email can exist across multiple firms).
 * The only trusted resolution path is NotificationProviderCorrelation,
 * populated by OutboundMailCorrelationService at send time and keyed
 * by mail.messageId — the SES event payload's own `mail.tags` field
 * (an echo of whatever tags this application itself attached) is never
 * used as a resolution mechanism, since it is unvalidated request
 * content, not a value this class independently verified. Once a
 * correlation is found, this class still cross-checks the event's own
 * bounced/complained recipient address against the correlation's own
 * stored recipient before doing anything — a correlation existing for
 * SOME message id is not proof it is the RIGHT message id if the
 * event payload's own content disagrees.
 */
class SesEventConsumerService
{
    public function __construct(
        private readonly SuppressionService $suppressionService,
        private readonly TenantContextService $tenantContext,
    ) {}

    public function process(string $sqsMessageId, string $rawBody): bool
    {
        $envelope = json_decode($rawBody, true);

        if (! is_array($envelope) || json_last_error() !== JSON_ERROR_NONE) {
            Log::error('ses_event_malformed_json', [
                'sqs_message_id' => $sqsMessageId,
            ]);

            return false;
        }

        // SNS always delivers SubscriptionConfirmation in the full JSON
        // envelope shape regardless of "raw message delivery" (that
        // setting only affects ongoing Notification-type messages) —
        // recognized and safely deleted, never treated as a business
        // event and never auto-confirmed by this consumer.
        if (($envelope['Type'] ?? null) === 'SubscriptionConfirmation') {
            Log::info('ses_event_sns_subscription_confirmation_skipped', [
                'sqs_message_id' => $sqsMessageId,
            ]);

            return true;
        }

        // Defensive unwrap: if raw message delivery were ever disabled
        // upstream, a genuine event notification would still arrive
        // SNS-wrapped with the real event JSON string-encoded inside
        // "Message". Detected and unwrapped rather than silently
        // misread as a malformed/unknown event shape.
        $event = $envelope;

        if (($envelope['Type'] ?? null) === 'Notification' && is_string($envelope['Message'] ?? null)) {
            $inner = json_decode($envelope['Message'], true);

            if (! is_array($inner) || json_last_error() !== JSON_ERROR_NONE) {
                Log::error('ses_event_malformed_sns_wrapped_message', [
                    'sqs_message_id' => $sqsMessageId,
                ]);

                return false;
            }

            $event = $inner;
        }

        $validated = $this->validate($event);

        if ($validated === null) {
            Log::error('ses_event_invalid_structure', [
                'sqs_message_id' => $sqsMessageId,
                'raw_event_type' => $event['eventType'] ?? null,
            ]);

            return false;
        }

        [$eventType, $mailMessageId, $bounceType, $recipients, $feedbackId] = $validated;

        $idempotencyKey = $eventType->value.':'.($feedbackId ?? $mailMessageId);

        if (SesEventReceipt::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            Log::info('ses_event_duplicate_skipped', [
                'sqs_message_id' => $sqsMessageId,
                'event_type' => $eventType->value,
                'ses_message_id' => $mailMessageId,
            ]);

            return true;
        }

        $correlation = NotificationProviderCorrelation::query()
            ->where('provider_message_id', $mailMessageId)
            ->first();

        if ($correlation === null) {
            Log::warning('ses_event_unresolved_correlation', [
                'sqs_message_id' => $sqsMessageId,
                'event_type' => $eventType->value,
                'ses_message_id' => $mailMessageId,
            ]);

            return false;
        }

        $normalizedRecipients = array_map(
            static fn (string $r): string => mb_strtolower(trim($r)),
            $recipients,
        );

        if (! in_array($correlation->recipient_normalized, $normalizedRecipients, true)) {
            Log::error('ses_event_recipient_mismatch', [
                'sqs_message_id' => $sqsMessageId,
                'event_type' => $eventType->value,
                'correlation_id' => $correlation->correlation_id,
            ]);

            return false;
        }

        $firm = Firm::query()->find($correlation->firm_id);

        if ($firm === null) {
            Log::error('ses_event_firm_not_found', [
                'sqs_message_id' => $sqsMessageId,
                'correlation_id' => $correlation->correlation_id,
                'firm_id' => $correlation->firm_id,
            ]);

            return false;
        }

        $this->applyOutcome($firm, $eventType, $bounceType, $correlation);

        Log::info('ses_event_processed', [
            'event_type' => $eventType->value,
            'ses_message_id' => $mailMessageId,
            'sqs_message_id' => $sqsMessageId,
            'correlation_id' => $correlation->correlation_id,
            'bounce_type' => $bounceType?->value,
        ]);

        SesEventReceipt::create([
            'idempotency_key' => $idempotencyKey,
            'event_type' => $eventType->value,
            'ses_message_id' => $mailMessageId,
            'sqs_message_id' => $sqsMessageId,
            'processed_at' => now(),
        ]);

        return true;
    }

    /**
     * Business-outcome dispatch. Deliberately narrow:
     *   - Permanent bounce -> suppress (SuppressionService::recordBounce()).
     *   - Complaint -> suppress (SuppressionService::recordComplaint()).
     *   - Transient/Undetermined bounce -> logged only by the caller,
     *     never suppressed (project rule — no existing policy calls
     *     for it, and this consumer does not invent one).
     *   - Reject/RenderingFailure/DeliveryDelay -> logged only by the
     *     caller, never suppressed.
     * Wraps the one suppression write (when applicable) in
     * runWithFirmContext($firm, ...) — SuppressionService's own
     * recordBounce()/recordComplaint() additionally wrap their own
     * single NotificationEvent::create() call, matching the repo's
     * established double-safety convention for FORCE-RLS writes.
     */
    private function applyOutcome(Firm $firm, SesEventType $eventType, ?SesBounceType $bounceType, NotificationProviderCorrelation $correlation): void
    {
        if ($eventType === SesEventType::Bounce && $bounceType !== SesBounceType::Permanent) {
            return;
        }

        if (! in_array($eventType, [SesEventType::Bounce, SesEventType::Complaint], true)) {
            return;
        }

        $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $eventType, $correlation): void {
            if ($eventType === SesEventType::Bounce) {
                $this->suppressionService->recordBounce(
                    $firm,
                    $correlation->recipient_normalized,
                    $correlation->channel,
                    $correlation->correlation_id,
                    'ses_bounce_permanent',
                );

                return;
            }

            $this->suppressionService->recordComplaint(
                $firm,
                $correlation->recipient_normalized,
                $correlation->channel,
                $correlation->correlation_id,
                'ses_complaint',
            );
        });
    }

    /**
     * @return array{0: SesEventType, 1: string, 2: ?SesBounceType, 3: array<int, string>, 4: ?string}|null
     */
    private function validate(array $event): ?array
    {
        $rawType = $event['eventType'] ?? null;

        if (! is_string($rawType)) {
            return null;
        }

        $eventType = SesEventType::tryFrom($rawType);

        if ($eventType === null) {
            return null;
        }

        $mailMessageId = $event['mail']['messageId'] ?? null;

        if (! is_string($mailMessageId) || $mailMessageId === '') {
            return null;
        }

        $bounceType = null;
        $recipients = [];
        $feedbackId = null;

        switch ($eventType) {
            case SesEventType::Bounce:
                $rawBounceType = $event['bounce']['bounceType'] ?? null;
                $bounceType = is_string($rawBounceType) ? SesBounceType::tryFrom($rawBounceType) : null;

                if ($bounceType === null) {
                    return null;
                }

                $recipients = $this->extractAddresses($event['bounce']['bouncedRecipients'] ?? null);
                $feedbackId = $event['bounce']['feedbackId'] ?? null;
                $feedbackId = is_string($feedbackId) && $feedbackId !== '' ? $feedbackId : null;

                break;

            case SesEventType::Complaint:
                $recipients = $this->extractAddresses($event['complaint']['complainedRecipients'] ?? null);
                $feedbackId = $event['complaint']['feedbackId'] ?? null;
                $feedbackId = is_string($feedbackId) && $feedbackId !== '' ? $feedbackId : null;

                break;

            case SesEventType::Reject:
            case SesEventType::RenderingFailure:
            case SesEventType::DeliveryDelay:
                $recipients = $this->extractAddresses($event['mail']['destination'] ?? null);

                break;
        }

        if ($recipients === []) {
            return null;
        }

        return [$eventType, $mailMessageId, $bounceType, $recipients, $feedbackId];
    }

    /**
     * Accepts either SES's own `[{"emailAddress": "..."}]` recipient
     * shape (bounce/complaint) or a plain `["a@b.com"]` string array
     * (mail.destination) — returns only well-formed, non-empty
     * address strings.
     *
     * @return array<int, string>
     */
    private function extractAddresses(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $addresses = [];

        foreach ($raw as $entry) {
            if (is_string($entry) && $entry !== '') {
                $addresses[] = $entry;

                continue;
            }

            if (is_array($entry) && is_string($entry['emailAddress'] ?? null) && $entry['emailAddress'] !== '') {
                $addresses[] = $entry['emailAddress'];
            }
        }

        return $addresses;
    }
}
