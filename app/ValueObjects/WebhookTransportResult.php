<?php

namespace App\ValueObjects;

use App\Enums\WebhookDeliveryAttemptOutcome;

/**
 * WebhookTransportResult — the uniform return shape every
 * WebhookTransportInterface implementation must produce, whether it's
 * FakeWebhookTransport (the only bound implementation in Phase 14) or a
 * future real HTTP transport. WebhookDispatchJob converts this directly
 * into exactly one webhook_delivery_attempts row — it never inspects a
 * transport-specific return shape itself.
 */
final readonly class WebhookTransportResult
{
    public function __construct(
        public WebhookDeliveryAttemptOutcome $outcome,
        public ?int $httpStatusCode = null,
        public ?string $responseSnippet = null,
    ) {
    }

    public static function success(?int $httpStatusCode = 200, ?string $responseSnippet = null): self
    {
        return new self(WebhookDeliveryAttemptOutcome::Success, $httpStatusCode, $responseSnippet);
    }

    public static function failure(?int $httpStatusCode = null, ?string $responseSnippet = null): self
    {
        return new self(WebhookDeliveryAttemptOutcome::Failure, $httpStatusCode, $responseSnippet);
    }

    public static function timeout(): self
    {
        return new self(WebhookDeliveryAttemptOutcome::Timeout, null, null);
    }
}
