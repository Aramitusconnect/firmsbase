<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationEventStatus;
use App\Enums\SesBounceType;
use App\Enums\SesEventType;
use App\Models\Firm;
use App\Models\NotificationProviderCorrelation;
use App\Models\PlatformNotificationCorrelation;
use App\Models\SesEventReceipt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
        private readonly PlatformNotificationCorrelationService $platformCorrelation,
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

        $normalizedRecipients = array_map(
            static fn (string $r): string => mb_strtolower(trim($r)),
            $recipients,
        );

        $correlation = NotificationProviderCorrelation::query()
            ->where('provider_message_id', $mailMessageId)
            ->first();

        if ($correlation !== null) {
            // provider_message_id is the authoritative resolution key —
            // a Complaint's own recipient list is not (post-578ee98
            // audit finding B4): ISP feedback loops are known to
            // redact/broaden complainedRecipients, and a mismatch there
            // must not throw away an otherwise-authoritative complaint.
            // Bounce events come directly from SES itself, not a
            // third-party feedback loop, so that redaction risk does
            // not apply the same way — a bounce mismatch remains a hard
            // reject.
            if (! in_array($correlation->recipient_normalized, $normalizedRecipients, true)) {
                if ($eventType === SesEventType::Complaint) {
                    Log::warning('ses_event_complaint_recipient_mismatch_defense_in_depth_only', [
                        'sqs_message_id' => $sqsMessageId,
                        'correlation_id' => $correlation->correlation_id,
                    ]);
                } else {
                    Log::error('ses_event_recipient_mismatch', [
                        'sqs_message_id' => $sqsMessageId,
                        'event_type' => $eventType->value,
                        'correlation_id' => $correlation->correlation_id,
                    ]);

                    return false;
                }
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

            return $this->recordReceipt($idempotencyKey, $eventType, $mailMessageId, $sqsMessageId);
        }

        // No firm-scoped correlation — try the platform-scope table
        // (post-578ee98 audit finding H1) before giving up. Never
        // touches SuppressionService/notification_events (firm-scoped,
        // FORCE-RLS): a platform-scope correlation exists precisely
        // because no firm could be resolved at send time.
        $platformCorrelation = PlatformNotificationCorrelation::query()
            ->where('provider_message_id', $mailMessageId)
            ->first();

        if ($platformCorrelation === null) {
            Log::warning('ses_event_unresolved_correlation', [
                'sqs_message_id' => $sqsMessageId,
                'event_type' => $eventType->value,
                'ses_message_id' => $mailMessageId,
            ]);

            return false;
        }

        $normalizedRecipientFingerprints = array_map(
            fn (string $r): string => $this->platformCorrelation->fingerprintFor($r),
            $normalizedRecipients,
        );

        if (! in_array($platformCorrelation->recipient_fingerprint, $normalizedRecipientFingerprints, true)) {
            if ($eventType === SesEventType::Complaint) {
                Log::warning('ses_event_platform_complaint_recipient_mismatch_defense_in_depth_only', [
                    'sqs_message_id' => $sqsMessageId,
                    'correlation_id' => $platformCorrelation->correlation_id,
                ]);
            } else {
                Log::error('ses_event_platform_recipient_mismatch', [
                    'sqs_message_id' => $sqsMessageId,
                    'event_type' => $eventType->value,
                    'correlation_id' => $platformCorrelation->correlation_id,
                ]);

                return false;
            }
        }

        $this->applyPlatformOutcome($eventType, $bounceType, $platformCorrelation);

        Log::info('ses_event_processed', [
            'event_type' => $eventType->value,
            'ses_message_id' => $mailMessageId,
            'sqs_message_id' => $sqsMessageId,
            'correlation_id' => $platformCorrelation->correlation_id,
            'bounce_type' => $bounceType?->value,
            'scope' => 'platform',
        ]);

        return $this->recordReceipt($idempotencyKey, $eventType, $mailMessageId, $sqsMessageId);
    }

    /**
     * The actual concurrency gate (post-578ee98 audit finding B3): the
     * unique constraint on ses_event_receipts.idempotency_key is what
     * makes this safe under concurrent processing, not the earlier
     * exists() pre-check alone (which is a plain SELECT and cannot
     * prevent two processes racing past it simultaneously). A unique-
     * violation here means another process already durably recorded
     * this exact event — our own outcome call above is itself
     * idempotent (SuppressionService/PlatformNotificationCorrelationService
     * both upsert-or-guard on write), so losing this race is a safe,
     * harmless no-op: still safe to delete the SQS message.
     */
    private function recordReceipt(string $idempotencyKey, SesEventType $eventType, string $mailMessageId, string $sqsMessageId): bool
    {
        try {
            // Wrapped in its own DB::transaction() specifically so a
            // caught unique-violation below never poisons any
            // surrounding transaction: on Postgres, an uncommitted
            // statement failure aborts the entire current transaction
            // block, not just the one statement — every later query in
            // that same transaction would then fail with "current
            // transaction is aborted" even though this exception is
            // caught. Laravel automatically issues a SAVEPOINT for a
            // transaction opened while already inside one (e.g. a
            // caller's own wrapping transaction, or a test's
            // RefreshDatabase transaction), so a rollback here is
            // scoped to just this insert.
            DB::transaction(function () use ($idempotencyKey, $eventType, $mailMessageId, $sqsMessageId): void {
                SesEventReceipt::create([
                    'idempotency_key' => $idempotencyKey,
                    'event_type' => $eventType->value,
                    'ses_message_id' => $mailMessageId,
                    'sqs_message_id' => $sqsMessageId,
                    'processed_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            Log::info('ses_event_receipt_race_lost_safe_duplicate', [
                'sqs_message_id' => $sqsMessageId,
                'event_type' => $eventType->value,
                'ses_message_id' => $mailMessageId,
            ]);
        }

        return true;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505';
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
     * Platform-scope analogue of applyOutcome() — identical narrow
     * bounce-type/event-type gating, but never touches SuppressionService
     * or notification_events (no firm exists to scope them to). Writes
     * to platform_notification_suppressions via
     * PlatformNotificationCorrelationService::recordOutcome() instead.
     */
    private function applyPlatformOutcome(SesEventType $eventType, ?SesBounceType $bounceType, PlatformNotificationCorrelation $correlation): void
    {
        if ($eventType === SesEventType::Bounce && $bounceType !== SesBounceType::Permanent) {
            return;
        }

        if (! in_array($eventType, [SesEventType::Bounce, SesEventType::Complaint], true)) {
            return;
        }

        $status = $eventType === SesEventType::Bounce
            ? NotificationEventStatus::Bounced
            : NotificationEventStatus::Complained;

        $this->platformCorrelation->recordOutcome(
            $correlation,
            $status,
            $eventType === SesEventType::Bounce ? 'ses_bounce_permanent' : 'ses_complaint',
        );
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
