<?php

namespace App\Services;

use App\Models\WebhookDelivery;
use App\ValueObjects\WebhookTransportResult;

/**
 * WebhookTransportInterface — the ONLY seam through which a webhook
 * delivery is ever "sent." The only bound implementation in Phase 14 is
 * FakeWebhookTransport (correction #4) — no real HTTP transport exists
 * in this phase, and none is wired into any service container binding
 * (no AppServiceProvider/config changes were made — correction #4).
 * Callers (WebhookDispatchJob) must resolve/inject an implementation of
 * this interface directly and explicitly rather than relying on a
 * container default, keeping the wiring visible and testable.
 *
 * A future real HTTP transport implementing this same interface would
 * need to: be timeout-limited, be retry-safe (idempotent from the
 * caller's perspective — WebhookDeliveryAttemptService already owns
 * retry state), re-resolve the destination hostname and re-check every
 * resolved IP via WebhookDestinationValidationService immediately
 * before opening the connection (this phase's validation is a
 * point-in-time, no-DNS check only — see WebhookDestinationValidationService's
 * docblock), and require separate approval before being bound anywhere.
 */
interface WebhookTransportInterface
{
    public function send(WebhookDelivery $delivery, string $signedPayload, array $headers): WebhookTransportResult;
}
