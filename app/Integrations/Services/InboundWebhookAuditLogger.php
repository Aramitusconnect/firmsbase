<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * InboundWebhookAuditLogger — the platform-only half of the frozen
 * design's two-sink audit design (Checkpoint 7,
 * reviews/checkpoint-07/frozen-design-post-security-review.md §14).
 * Used ONLY for pre-resolution / resolution-failure events (webhook
 * request received, route identity resolved, signature rejected before
 * a firm is known, etc.) — App\Services\TimelineEventRecorder (the
 * OTHER sink) hard-requires a non-null `App\Models\Firm` and must NEVER
 * be fed a fabricated tentative firm attribution; anything this class
 * logs happens either before a firm is known at all, or is
 * deliberately platform-scoped even when a firm happens to be known
 * (e.g. `disconnected_event_rejected`, which is itself an
 * anti-enumeration-sensitive rejection, not a firm-facing activity
 * item).
 *
 * Logs to a NEW, dedicated, platform-only structured log channel —
 * built ad hoc via `Log::build()` rather than a `config/logging.php`
 * channel entry, since that config file is outside this checkpoint's
 * frozen production-file allowlist. This is a disclosed, narrow
 * implementation decision the frozen design does not spell out at this
 * level of detail; the channel is functionally identical to a
 * pre-registered one (writes structured lines to its own dedicated log
 * file, independent of the default application log), just constructed
 * on demand.
 *
 * $eventName MUST be one of the 11 frozen, closed event names below
 * (frozen design §14) — this class refuses to log anything else, so a
 * future call site cannot silently introduce a new, unreviewed event
 * name. $context is defensively stripped of any key matching the
 * forbidden-key denylist before it is ever written, as a second,
 * independent layer beneath "callers must not pass secret material in
 * the first place" — never a substitute for that discipline.
 *
 * NEVER logs: the raw routing token, the route-token hash when
 * unnecessary, the signature value, the webhook secret (in any form,
 * plaintext or ciphertext), the raw `Authorization` header, access/
 * refresh tokens, the complete raw request body, or any confidential
 * legal-client data.
 */
final class InboundWebhookAuditLogger
{
    public const EVENT_REQUEST_RECEIVED = 'integration_webhook.request_received';

    public const EVENT_ROUTE_IDENTITY_RESOLVED = 'integration_webhook.route_identity_resolved';

    public const EVENT_SIGNATURE_VERIFIED = 'integration_webhook.signature_verified';

    public const EVENT_SIGNATURE_REJECTED = 'integration_webhook.signature_rejected';

    public const EVENT_REPLAY_REJECTED = 'integration_webhook.replay_rejected';

    public const EVENT_DUPLICATE_ACCEPTED = 'integration_webhook.duplicate_accepted';

    public const EVENT_TENANT_EVENT_CREATED = 'integration_webhook.tenant_event_created';

    public const EVENT_DISCONNECTED_EVENT_REJECTED = 'integration_webhook.disconnected_event_rejected';

    public const EVENT_PROCESSING_HANDOFF_CREATED = 'integration_webhook.processing_handoff_created';

    public const EVENT_PROCESSING_FAILED = 'integration_webhook.processing_failed';

    public const EVENT_SECRET_ROTATION_USED = 'integration_webhook.secret_rotation_used';

    /**
     * @var string[]
     */
    private const ALLOWED_EVENT_NAMES = [
        self::EVENT_REQUEST_RECEIVED,
        self::EVENT_ROUTE_IDENTITY_RESOLVED,
        self::EVENT_SIGNATURE_VERIFIED,
        self::EVENT_SIGNATURE_REJECTED,
        self::EVENT_REPLAY_REJECTED,
        self::EVENT_DUPLICATE_ACCEPTED,
        self::EVENT_TENANT_EVENT_CREATED,
        self::EVENT_DISCONNECTED_EVENT_REJECTED,
        self::EVENT_PROCESSING_HANDOFF_CREATED,
        self::EVENT_PROCESSING_FAILED,
        self::EVENT_SECRET_ROTATION_USED,
    ];

    /**
     * Defense-in-depth denylist — see class docblock. Matched
     * case-insensitively against $context array keys.
     *
     * @var string[]
     */
    private const FORBIDDEN_CONTEXT_KEYS = [
        'raw_routing_token', 'routing_token', 'routing_token_hash',
        'signature', 'signature_value', 'raw_signature',
        'secret', 'plaintext_secret', 'webhook_secret', 'webhook_signing_secret',
        'authorization', 'cookie',
        'raw_body', 'body', 'headers', 'payload', 'request_body',
    ];

    private ?LoggerInterface $logger = null;

    public function record(string $eventName, array $context = []): void
    {
        if (! in_array($eventName, self::ALLOWED_EVENT_NAMES, true)) {
            throw new InvalidArgumentException(
                "InboundWebhookAuditLogger::record() refuses unknown event name '{$eventName}' — ".
                'only the 11 frozen event names (see class docblock) may be logged.'
            );
        }

        $this->logger()->info($eventName, $this->stripForbiddenKeys($context));
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger === null) {
            $this->logger = Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/integration-webhook-audit.log'),
                'level' => 'info',
            ]);
        }

        return $this->logger;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function stripForbiddenKeys(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_CONTEXT_KEYS, true)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->stripForbiddenKeys($value) : $value;
        }

        return $sanitized;
    }
}
