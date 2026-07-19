<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * SupportsWebhooksContract — implemented only by providers that push
 * inbound events to FirmsBase.
 *
 * REQUIRED SECURITY-REVIEW FIX (checkpoint-00-final-specification.md
 * §11/§21): this contract MUST STAY WIRE-LOCATION-AGNOSTIC. It does
 * not assume or encode WHERE a routing/identity token arrives on the
 * wire (query parameter vs. HTTP header vs. request-body field) — that
 * decision is explicitly deferred to Checkpoint 7, pending the
 * dedicated adversarial security review described in §11(d). No method
 * name, parameter name, or docblock in this interface may imply a
 * specific wire convention. `$headers` below is a generic associative
 * array supplied by the caller (whatever the caller's own inbound
 * request-handling code decides to populate it with) — it is not
 * necessarily literally HTTP headers, and this contract does not
 * assume any particular key exists in it.
 *
 * No webhook route, receipt table, or signature-verification call site
 * exists yet at Checkpoint 1 — interface shape only.
 */
interface SupportsWebhooksContract
{
    /**
     * @return string[] the closed set of inbound event types this
     *                   provider may emit (mirrors the existing
     *                   WebhookEventTypeRegistry's closed-registry
     *                   shape for the platform's own outbound
     *                   webhooks).
     */
    public function webhookEventTypes(): array;

    /**
     * Verify an inbound payload's signature/authenticity. Must operate
     * on the exact raw bytes supplied (never a re-serialized/re-decoded
     * form, which could differ byte-for-byte from what was actually
     * signed).
     *
     * @param array<string, mixed> $headers generic associative context
     *                                       supplied by the caller —
     *                                       never assumed to be
     *                                       literally a specific wire
     *                                       location.
     */
    public function verifyInboundSignature(string $rawBody, array $headers): bool;

    /**
     * Parse a verified raw inbound payload into a normalized event
     * shape. Must only be called after verifyInboundSignature()
     * succeeds.
     *
     * @param array<string, mixed> $headers same generic, wire-location
     *                                       agnostic context as
     *                                       verifyInboundSignature().
     * @return array<string, mixed> normalized event data (e.g. event
     *                               type, provider event id, payload).
     */
    public function parseInboundEvent(string $rawBody, array $headers): array;

    /**
     * Ask the provider to start sending webhook events for this
     * connection/context.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed> subscription state (e.g.
     *                               subscription id, expiry).
     */
    public function subscribe(array $context): array;

    /**
     * Renew an existing subscription before it expires.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed> renewed subscription state.
     */
    public function renewSubscription(array $context): array;
}
