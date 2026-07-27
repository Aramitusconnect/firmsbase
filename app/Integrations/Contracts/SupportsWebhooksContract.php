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
 *
 * CHECKPOINT 1 (FirmsVault Live Integrations) additions — provider-
 * parameterized verification (checkpoint1-design-webhook-verification.md,
 * checkpoint1-combined-design.md §2.1, checkpoint1-security-review.md
 * Finding 2): two new methods below
 * (detectSubscriptionValidationChallenge(), extractRoutingIdentifier())
 * let App\Integrations\Http\Controllers\InboundWebhookController fully
 * delegate verification/parsing/challenge-handling/routing to whichever
 * provider is resolved, instead of hardcoding one provider's wire
 * convention. Additionally, `$headers` on the two PRE-EXISTING methods
 * below (verifyInboundSignature(), parseInboundEvent()) MAY now carry
 * one reserved, framework-injected synthetic key —
 * self::SECRET_CANDIDATES_HEADER_KEY — holding up to two live,
 * plaintext webhook-signing-secret candidates (string[], possibly
 * empty) resolved by the controller immediately before the call.
 * Implementations MUST NOT log, throw (in any exception message),
 * persist, or otherwise echo `$headers` verbatim or that key's value —
 * a provider that needs the candidates for HMAC-style verification (the
 * only case that reads this key) must read it, use it, and let it fall
 * out of scope; a provider with no secret-candidate concept (e.g. a
 * validation-token or JWK-based scheme) simply never accesses the key.
 * This constraint applies to verifyInboundSignature() and
 * parseInboundEvent() only — detectSubscriptionValidationChallenge()
 * and extractRoutingIdentifier() are always called with the plain
 * request `$headers`, before this synthetic key is ever injected.
 */
interface SupportsWebhooksContract
{
    /**
     * Reserved synthetic key the controller MAY inject into the
     * `$headers` array passed to verifyInboundSignature()/
     * parseInboundEvent() (never to detectSubscriptionValidationChallenge()/
     * extractRoutingIdentifier(), which run before this key would even
     * be resolvable). Holds up to two live plaintext webhook-signing-
     * secret candidates (string[], possibly empty) — see this
     * interface's class docblock for the full handling requirement.
     * Never a literal HTTP header name; chosen to be exceedingly
     * unlikely to collide with any real header/context key a provider
     * might otherwise populate `$headers` with.
     */
    public const SECRET_CANDIDATES_HEADER_KEY = '__firmsvault_resolved_webhook_secrets';

    /**
     * @return string[] the closed set of inbound event types this
     *                  provider may emit (mirrors the existing
     *                  WebhookEventTypeRegistry's closed-registry
     *                  shape for the platform's own outbound
     *                  webhooks).
     */
    public function webhookEventTypes(): array;

    /**
     * Verify an inbound payload's signature/authenticity. Must operate
     * on the exact raw bytes supplied (never a re-serialized/re-decoded
     * form, which could differ byte-for-byte from what was actually
     * signed).
     *
     * @param  array<string, mixed>  $headers  generic associative context
     *                                         supplied by the caller —
     *                                         never assumed to be
     *                                         literally a specific wire
     *                                         location. MAY carry
     *                                         self::SECRET_CANDIDATES_HEADER_KEY
     *                                         (live plaintext secret
     *                                         material) — see this
     *                                         interface's class docblock;
     *                                         implementations MUST NOT
     *                                         log/throw/echo $headers or
     *                                         that key's value.
     */
    public function verifyInboundSignature(string $rawBody, array $headers): bool;

    /**
     * Parse a verified raw inbound payload into a normalized event
     * shape. Must only be called after verifyInboundSignature()
     * succeeds.
     *
     * By convention (not a type-level constraint — the return type is
     * still open array<string, mixed>), the returned array is expected
     * to carry `event_id` (?string), `event_type` (?string), and
     * `payload` (array); implementations MAY additionally carry an
     * optional `signature_scheme` string key (e.g.
     * 'ms-graph-clientstate', 'test-hmac-v1') that the controller reads
     * if present, purely for informational/audit purposes.
     *
     * @param  array<string, mixed>  $headers  same generic, wire-location
     *                                         agnostic context as
     *                                         verifyInboundSignature(),
     *                                         including the same
     *                                         self::SECRET_CANDIDATES_HEADER_KEY
     *                                         handling requirement — see
     *                                         this interface's class
     *                                         docblock.
     * @return array<string, mixed> normalized event data (e.g. event
     *                              type, provider event id, payload).
     */
    public function parseInboundEvent(string $rawBody, array $headers): array;

    /**
     * Ask the provider to start sending webhook events for this
     * connection/context.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed> subscription state (e.g.
     *                              subscription id, expiry).
     */
    public function subscribe(array $context): array;

    /**
     * Renew an existing subscription before it expires.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed> renewed subscription state.
     */
    public function renewSubscription(array $context): array;

    /**
     * CHECKPOINT 1 addition (checkpoint1-design-webhook-verification.md
     * §4/§5). Detect whether this inbound request is a subscription-
     * verification challenge (e.g. Microsoft Graph's `validationToken`
     * handshake) rather than a real event delivery. Returns null for
     * "not a challenge — proceed with the normal per-event pipeline."
     * Providers with no such concept (Google, Plaid, TestProvider)
     * always return null. Must never throw. Called BEFORE any
     * routing/connection resolution — no firm/connection context is
     * available or assumed here, and $headers here is always the plain
     * request headers (never carries
     * self::SECRET_CANDIDATES_HEADER_KEY).
     *
     * @param  array<string, mixed>  $queryParams
     * @param  array<string, mixed>  $headers
     * @return array{body: string, status: int, content_type: string}|null
     */
    public function detectSubscriptionValidationChallenge(array $queryParams, array $headers): ?array;

    /**
     * CHECKPOINT 1 addition (checkpoint1-design-webhook-verification.md
     * §1.3/§5). Extract this provider's own routing/correlation
     * identifier — the raw value that, once hashed, is looked up
     * against `integration_webhook_routing_index` to identify the
     * target FirmIntegration. May read $headers (e.g. Google's
     * X-Goog-Channel-Token) or defensively decode $rawBody for a single
     * field (e.g. Microsoft's clientState body field, Plaid's item_id).
     * Must never throw; returns null on any extraction failure,
     * treated identically to "unknown identifier" (anti-enumeration
     * collapse-to-false). $headers here is always the plain request
     * headers (never carries self::SECRET_CANDIDATES_HEADER_KEY — that
     * key is only ever injected for the later
     * verifyInboundSignature()/parseInboundEvent() calls, once a
     * connection identity is already being resolved).
     */
    public function extractRoutingIdentifier(string $rawBody, array $headers): ?string;
}
