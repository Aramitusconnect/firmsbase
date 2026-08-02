<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationEventStatus;
use App\Models\PlatformNotificationCorrelation;
use App\Models\PlatformNotificationSuppression;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * PlatformNotificationCorrelationService — post-578ee98 audit
 * remediation (finding H1). The platform-scope analogue of
 * OutboundMailCorrelationService, for the narrow case where a governed
 * real send (today: password-reset notifications on User/
 * ClientPortalUser) cannot resolve an owning firm at all. The original
 * design simply sent these uncorrelated and untracked; this service
 * gives them a durable, tenant-agnostic correlation instead, so a
 * later bounce/complaint can still be recorded and, critically,
 * repeated sends to a permanently-bad address can be prevented —
 * without ever touching a firm-scoped table
 * (SuppressionService/notification_events), since no firm is known.
 *
 * recipient_fingerprint (both here and on
 * platform_notification_suppressions) is a KEYED HMAC-SHA256, never
 * plaintext — mirrors
 * App\Integrations\Support\GmailMailboxRoutingService's own established
 * "a small, structured, guessable string must never be stored as a
 * plain hash" discipline. Keyed by a dedicated, platform-wide secret
 * (config('services.platform_notifications.recipient_fingerprint_hmac_key')),
 * fail-closed if missing — never derived from APP_KEY.
 *
 * Listener lifecycle matches OutboundMailCorrelationService's own
 * post-audit fix: the MessageSent listener is scoped to exactly one
 * correlate() call via a save/restore of the dispatcher's existing
 * MessageSent listeners in a finally block.
 */
class PlatformNotificationCorrelationService
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    /**
     * Checked BEFORE attempting a real send on the uncorrelated-firm
     * fallback path. Returns true only for a fingerprint this service
     * itself has previously recorded as bounced/complained via
     * recordOutcome() — never guesses, never treats "not found" as
     * anything but "not suppressed".
     */
    public function isRecipientSuppressed(string $recipient): bool
    {
        $fingerprint = $this->fingerprint($this->normalize($recipient));

        return PlatformNotificationSuppression::query()
            ->where('recipient_fingerprint', $fingerprint)
            ->exists();
    }

    public function correlate(string $accountType, int $accountId, string $notificationType, string $recipient, \Closure $send): void
    {
        $correlationId = (string) Str::uuid();
        $fingerprint = $this->fingerprint($this->normalize($recipient));

        PlatformNotificationCorrelation::create([
            'correlation_id' => $correlationId,
            'account_type' => $accountType,
            'account_id' => $accountId,
            'notification_type' => $notificationType,
            'recipient_fingerprint' => $fingerprint,
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
            Log::warning('platform_notification_correlation_no_provider_message_id', [
                'correlation_id' => $correlationId,
                'account_type' => $accountType,
                'account_id' => $accountId,
                'notification_type' => $notificationType,
            ]);

            return;
        }

        PlatformNotificationCorrelation::where('correlation_id', $correlationId)
            ->update(['provider_message_id' => $providerMessageId]);
    }

    /**
     * Called only by SesEventConsumerService, once it has resolved a
     * PlatformNotificationCorrelation by provider_message_id — never by
     * any firm-scoped code path. Upserts by recipient_fingerprint, so a
     * duplicate/replayed event for the same address is a safe no-op —
     * this table's own idempotency guard, independent of the
     * ses_event_receipts ledger (defense in depth).
     */
    public function recordOutcome(PlatformNotificationCorrelation $correlation, NotificationEventStatus $status, ?string $reason = null): void
    {
        PlatformNotificationSuppression::query()->updateOrCreate(
            ['recipient_fingerprint' => $correlation->recipient_fingerprint],
            [
                'status' => $status,
                'correlation_id' => $correlation->correlation_id,
                'reason' => $reason,
                'suppressed_at' => now(),
            ],
        );
    }

    public function fingerprintFor(string $recipient): string
    {
        return $this->fingerprint($this->normalize($recipient));
    }

    private function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function fingerprint(string $normalizedRecipient): string
    {
        return hash_hmac('sha256', $normalizedRecipient, $this->hmacKey());
    }

    /**
     * Fail-closed: a missing/empty configured key is a configuration
     * defect, never silently substituted with a weaker/derived value
     * (e.g. APP_KEY) — mirrors GmailMailboxRoutingService::hmacKey()'s
     * identical established discipline.
     */
    private function hmacKey(): string
    {
        $key = config('services.platform_notifications.recipient_fingerprint_hmac_key');

        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException(
                'PlatformNotificationCorrelationService requires services.platform_notifications.recipient_fingerprint_hmac_key '.
                'to be configured with a dedicated, platform-wide secret — never derived from APP_KEY, never reused across purposes.'
            );
        }

        return $key;
    }
}
