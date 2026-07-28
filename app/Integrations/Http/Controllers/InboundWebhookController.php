<?php

declare(strict_types=1);

namespace App\Integrations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Integrations\Contracts\SupportsWebhooksContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\ResolvedWebhookConnection;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\WebhookVerificationOutcome;
use App\Integrations\Jobs\RecordWebhookVerificationFailureJob;
use App\Integrations\Listeners\DispatchPlaidItemLifecycleTransitionOnVerifiedWebhookEvent;
use App\Integrations\Listeners\DispatchPullSyncOnVerifiedWebhookEvent;
use App\Integrations\Models\IntegrationInboundWebhookEvent;
use App\Integrations\Services\InboundWebhookAuditLogger;
use App\Integrations\Services\InboundWebhookEventService;
use App\Integrations\Services\InboundWebhookReceiptService;
use App\Integrations\Services\InboundWebhookSignatureVerifier;
use App\Integrations\Services\WebhookConnectionResolverService;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * InboundWebhookController — the ONLY HTTP entry point for
 * `POST /webhooks/integrations/{provider}` (originally Checkpoint 7,
 * reviews/checkpoint-07/frozen-design-post-security-review.md §1/§8;
 * rewired for provider-parameterized verification in Checkpoint 1 of
 * the FirmsVault Live Integrations mission,
 * checkpoint1-design-webhook-verification.md,
 * checkpoint1-combined-design.md §2.1). Unauthenticated, no session, no
 * CSRF (see routes/webhooks.php — this route is deliberately registered
 * outside the `web` middleware group).
 *
 * CHECKPOINT 1 REWIRE: this controller no longer hardcodes one
 * provider's wire convention (three `X-Test-Provider-*` header names,
 * an inline `v1=<hex>` signature-version parser, a direct call to
 * App\Integrations\Services\InboundWebhookSignatureVerifier). It now
 * resolves the real code-level provider instance via
 * App\Integrations\Core\ProviderRegistry and delegates every
 * provider-specific decision (validation-challenge detection, routing-
 * identifier extraction, signature verification, event parsing) to
 * App\Integrations\Contracts\SupportsWebhooksContract — see that
 * interface's own docblock for the full method-by-method contract.
 * Receipt/event persistence, audit logging, response shaping, and the
 * collapse-to-false discipline below are unchanged from Checkpoint 7.
 *
 * Collapse-to-false: every rejection reachable before signature
 * verification succeeds returns the byte-identical
 * `401 {"status":"rejected"}` response. This controller never branches
 * its RESPONSE on which rejection reason occurred — only its internal,
 * platform-only audit logging and the new (Checkpoint 1)
 * RecordWebhookVerificationFailureJob dispatch (never exposed to the
 * caller) may vary by reason.
 *
 * CHECKPOINT 1 addition (security review Finding 5): every rejection
 * branch that returns the collapsed 401 (plus the distinct
 * malformed_payload 400 branch) dispatches
 * App\Integrations\Jobs\RecordWebhookVerificationFailureJob — a queued
 * job, deliberately NEVER a synchronous, blocking database write on
 * this timing-critical request path.
 */
final class InboundWebhookController extends Controller
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly WebhookConnectionResolverService $resolver,
        private readonly InboundWebhookSignatureVerifier $verifier,
        private readonly InboundWebhookReceiptService $receipts,
        private readonly InboundWebhookEventService $events,
        private readonly InboundWebhookAuditLogger $auditLogger,
        private readonly TimelineEventRecorder $timelineEvents,
    ) {}

    public function __invoke(Request $request, string $provider): JsonResponse|Response
    {
        // Raw bytes read FIRST — before any $request->all()/input()/
        // json() call (unchanged discipline since Checkpoint 7).
        $rawBody = $request->getContent();

        $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_REQUEST_RECEIVED, [
            'provider' => $provider,
        ]);

        // STEP 1 (checkpoint1-design-webhook-verification.md §1.6 step
        // 2) — resolve ProviderKey/ProviderRegistry FIRST: cheap,
        // in-memory, no DB round trip. On failure (unknown key, not
        // registered, or the resolved instance doesn't implement
        // SupportsWebhooksContract at all), collapse into the SAME
        // padding+rejection path as an unknown routing token — an
        // attacker must not be able to distinguish "provider code not
        // registered" from "wrong routing token."
        $providerKey = ProviderKey::tryFrom($provider);
        $providerInstance = null;

        if ($providerKey !== null && $this->registry->has($providerKey)) {
            try {
                $candidate = $this->registry->get($providerKey);
            } catch (Throwable) {
                $candidate = null;
            }

            if ($candidate instanceof SupportsWebhooksContract) {
                $providerInstance = $candidate;
            }
        }

        if ($providerInstance === null) {
            return $this->rejectEarly($provider, 'unknown_routing_token');
        }

        // STEP 2 — build the generic, wire-location-agnostic
        // query/header context every SupportsWebhooksContract method
        // below receives. $headers here NEVER carries the synthetic
        // secret-candidates key (that is only injected immediately
        // before the verifyInboundSignature() call, once a connection
        // identity is already being resolved).
        $queryParams = $request->query();
        $headers = $this->extractHeaders($request);

        // STEP 3 (design §4/§5) — detect a subscription-validation
        // challenge (e.g. Microsoft Graph's validationToken handshake).
        // Runs BEFORE any routing/connection resolution — no
        // firm/connection context is available or assumed here.
        try {
            $challenge = $providerInstance->detectSubscriptionValidationChallenge($queryParams, $headers);
        } catch (Throwable) {
            return $this->errored();
        }

        if ($challenge !== null) {
            if (! $this->isAcceptableContentType($request->header('Content-Type'), true)) {
                return $this->rejectEarly($provider, 'malformed_payload');
            }

            $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_VALIDATION_CHALLENGE_ANSWERED, [
                'provider' => $provider,
            ]);

            return response($challenge['body'], $challenge['status'])
                ->header('Content-Type', $challenge['content_type']);
        }

        // STEP 4 (design §6) — content-type allowlist for the normal
        // per-event pipeline. A shape check, not a provider-identity
        // check: every provider is held to the same JSON-family
        // requirement here.
        if (! $this->isAcceptableContentType($request->header('Content-Type'), false)) {
            return $this->rejectEarly($provider, 'malformed_payload');
        }

        // STEP 5 (design §1.3/§5) — provider-specific routing/
        // correlation identifier extraction, replacing the old
        // hardcoded ROUTING_TOKEN_HEADER read.
        try {
            $routingIdentifier = $providerInstance->extractRoutingIdentifier($rawBody, $headers);
        } catch (Throwable) {
            return $this->errored();
        }

        if ($routingIdentifier === null) {
            return $this->rejectEarly($provider, 'missing_headers');
        }

        try {
            $resolved = $this->resolver->resolveConnectionIdentity($provider, $routingIdentifier);
        } catch (Throwable) {
            return $this->errored();
        }

        if ($resolved === null) {
            return $this->rejectEarly($provider, 'unknown_routing_token');
        }

        $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_ROUTE_IDENTITY_RESOLVED, [
            'provider' => $provider,
            'firm_integration_id' => $resolved->firmIntegrationId,
        ]);

        // STEP 6 (design §1.4, the Finding-2-adjacent bug fix) — a
        // connection that is Active but has zero webhook-signing-secret
        // credentials must NOT be rejected outright (true for
        // Microsoft/Google/Plaid, whose verification never consults a
        // symmetric secret at all); only "connection not found/not
        // Active" is a real, provider-agnostic rejection reason.
        try {
            $isActive = $this->resolver->isConnectionActive($resolved);
        } catch (Throwable) {
            return $this->errored();
        }

        if (! $isActive) {
            // This path already did the real transaction/RLS-scoped
            // read; no extra padding is required (padding is scoped to
            // the early-exit cases above only).
            $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_DISCONNECTED_EVENT_REJECTED, [
                'firm_integration_id' => $resolved->firmIntegrationId,
            ]);

            $this->dispatchVerificationFailure($provider, 'disconnected_event_rejected');

            return $this->rejected();
        }

        try {
            $candidates = $this->resolver->activeAndPreviousWebhookSecretsFor($resolved);
        } catch (Throwable) {
            return $this->errored();
        }

        if (count($candidates) > 1) {
            $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_SECRET_ROTATION_USED, [
                'firm_integration_id' => $resolved->firmIntegrationId,
            ]);
        }

        // STEP 7 (design §1.5, CORRECTED per security review Finding
        // 2): a COPY of $headers, never a union (`+`) and never an
        // in-place mutation of $headers itself — explicit unset() then
        // assign, so a pre-existing header colliding with the reserved
        // key name can never smuggle a value in ahead of the
        // framework's own authoritative candidates. $headers itself,
        // used later for parseInboundEvent(), stays untouched and never
        // carries the secret.
        $forVerification = $headers;
        unset($forVerification[SupportsWebhooksContract::SECRET_CANDIDATES_HEADER_KEY]);
        $forVerification[SupportsWebhooksContract::SECRET_CANDIDATES_HEADER_KEY] = $candidates;

        try {
            $verified = $providerInstance->verifyInboundSignature($rawBody, $forVerification);
        } catch (Throwable) {
            unset($candidates, $forVerification);

            return $this->errored();
        }

        // Discard immediately — never logged/persisted/queued beyond
        // this point.
        unset($candidates, $forVerification);

        if (! $verified) {
            $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_SIGNATURE_REJECTED, [
                'firm_integration_id' => $resolved->firmIntegrationId,
            ]);

            $this->dispatchVerificationFailure($provider, 'signature_mismatch');

            return $this->rejected();
        }

        $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_SIGNATURE_VERIFIED, [
            'firm_integration_id' => $resolved->firmIntegrationId,
        ]);

        try {
            $parsed = $providerInstance->parseInboundEvent($rawBody, $headers);
        } catch (Throwable) {
            return $this->errored();
        }

        return $this->handleVerifiedDelivery($provider, $resolved, $rawBody, $routingIdentifier, $parsed);
    }

    /**
     * @param  array<string, mixed>  $parsed  normalized event data per
     *                                        SupportsWebhooksContract::parseInboundEvent()'s
     *                                        return convention
     *                                        (event_id, event_type,
     *                                        payload, optionally
     *                                        signature_scheme).
     */
    private function handleVerifiedDelivery(
        string $provider,
        ResolvedWebhookConnection $resolved,
        string $rawBody,
        string $routingIdentifier,
        array $parsed,
    ): JsonResponse {
        $bodyHash = hash('sha256', $rawBody);
        $routingTokenHash = hash('sha256', $routingIdentifier);

        // design §3 option 2 — an optional, informational-only
        // signature_scheme key a provider MAY supply; null otherwise
        // (never re-derived from a hardcoded `v1=<hex>` parse anymore).
        $signatureSchemeRaw = $parsed['signature_scheme'] ?? null;
        $signatureVersion = is_string($signatureSchemeRaw) ? $signatureSchemeRaw : null;

        $providerEventId = $parsed['event_id'] ?? null;
        $eventTypeRaw = $parsed['event_type'] ?? null;
        $eventType = is_string($eventTypeRaw) ? $eventTypeRaw : null;

        if (! is_string($providerEventId) || trim($providerEventId) === '') {
            // Malformed payload: a distinct failure occurring AFTER
            // verification succeeded. A receipt row IS written
            // (verification genuinely succeeded), but NO event row.
            try {
                $this->receipts->recordReceipt(
                    providerKey: $provider,
                    routingTokenHash: $routingTokenHash,
                    bodyHash: $bodyHash,
                    outcome: WebhookVerificationOutcome::Malformed,
                    requestCorrelationId: null,
                    providerEventId: null,
                    signatureVersion: $signatureVersion,
                    providerTimestamp: null,
                    failureCode: 'malformed_payload',
                );
            } catch (Throwable) {
                return $this->errored();
            }

            $this->dispatchVerificationFailure($provider, 'malformed_payload');

            return response()->json(['status' => 'rejected', 'reason' => 'malformed_payload'], 400);
        }

        try {
            $receipt = $this->receipts->recordReceipt(
                providerKey: $provider,
                routingTokenHash: $routingTokenHash,
                bodyHash: $bodyHash,
                outcome: WebhookVerificationOutcome::Verified,
                requestCorrelationId: null,
                providerEventId: $providerEventId,
                signatureVersion: $signatureVersion,
                providerTimestamp: null,
                failureCode: null,
            );
        } catch (Throwable) {
            // Durable-receipt-write failure — no 202 is ever sent; the
            // sender's own retry logic should treat this as retryable.
            return $this->errored();
        }

        try {
            $result = $this->events->recordVerifiedEvent(
                firmId: $resolved->firmId,
                firmIntegrationId: $resolved->firmIntegrationId,
                receiptId: (int) $receipt->id,
                providerKey: $provider,
                providerEventId: $providerEventId,
                receiptBodyHash: $bodyHash,
                eventType: $eventType,
                // No per-provider payload-field allowlist builder
                // exists yet (see
                // App\Integrations\Services\InboundWebhookEventService's
                // own class docblock) — an empty reference is the
                // conservative, always-safe default.
                sanitizedPayloadReference: [],
                receivedAt: $receipt->received_at,
            );
        } catch (Throwable) {
            return $this->errored();
        }

        if ($result['was_newly_created']) {
            $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_TENANT_EVENT_CREATED, [
                'firm_integration_id' => $resolved->firmIntegrationId,
            ]);

            $this->recordFirmTimelineEvent($resolved->firmId, $result['event']);

            // FirmsVault Live Integrations, Checkpoint 2
            // (checkpoint2-design-sync-webhooks.md §5.3;
            // checkpoint2-combined-design.md §2 P-21) — closes the
            // "verified webhook event never triggers sync" gap. Fired
            // ONLY for a genuinely newly-created event row (same
            // duplicate-guard as recordFirmTimelineEvent() immediately
            // above — a retried/duplicate delivery must never re-trigger
            // a second sync for the same event), dispatched directly
            // (never a framework Illuminate event listener — see
            // DispatchPullSyncOnVerifiedWebhookEvent's own docblock for
            // why: recordVerifiedEvent() writes via a raw DB::table()
            // insert, which never fires Eloquent's `created` event).
            DispatchPullSyncOnVerifiedWebhookEvent::dispatch(
                $resolved->firmIntegrationId,
                $resolved->firmId,
                $provider,
                $result['event']->event_type,
                (int) $result['event']->id,
            );

            // FirmsVault Live Integrations, Checkpoint 4 ("Plaid
            // financial evidence add-on" §6) — closes the "verified
            // lifecycle:item_* webhook event never applies an Item
            // status transition" gap
            // (PlaidItemErrorStateLifecycleGapTest.php). Same
            // duplicate-guard, same direct-dispatch reasoning as
            // DispatchPullSyncOnVerifiedWebhookEvent immediately above —
            // see that listener's own docblock for why it is
            // Plaid-specific rather than folded into the provider-
            // agnostic sync dispatch.
            DispatchPlaidItemLifecycleTransitionOnVerifiedWebhookEvent::dispatch(
                $resolved->firmIntegrationId,
                $resolved->firmId,
                $provider,
                $result['event']->event_type,
                (int) $result['event']->id,
            );
        } else {
            $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_DUPLICATE_ACCEPTED, [
                'firm_integration_id' => $resolved->firmIntegrationId,
            ]);
        }

        // A valid new event and a valid duplicate both return exactly
        // this response.
        return response()->json(['status' => 'accepted'], 202);
    }

    /**
     * Firm-attributed sink of the two-sink audit design — via the
     * EXISTING, unmodified App\Services\TimelineEventRecorder, only for
     * a genuinely newly-created event row (never on a re-selected
     * duplicate).
     */
    private function recordFirmTimelineEvent(int $firmId, IntegrationInboundWebhookEvent $event): void
    {
        (new TenantContextService)->runWithFirmContext($firmId, function () use ($event): void {
            $this->timelineEvents->record($event->firm, 'integration_webhook.inbound_event_verified', $event, null, [
                'firm_integration_id' => $event->firm_integration_id,
                'provider_key' => $event->provider_key,
            ]);
        });
    }

    /**
     * CHECKPOINT 1 helper — the shared early-exit path for every
     * rejection reachable BEFORE a connection identity has been
     * resolved (unknown/unregistered provider, unacceptable content
     * type, missing routing identifier, unknown routing token).
     * Performs the required timing-oracle mitigation padding exactly
     * once, records the platform-only audit log entry, dispatches the
     * new (Checkpoint 1) verification-failure counter job, and returns
     * the collapsed 401 response.
     */
    private function rejectEarly(string $provider, string $failureReason): JsonResponse
    {
        $this->verifier->performConstantWorkPadding();

        $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_SIGNATURE_REJECTED, [
            'provider' => $provider,
        ]);

        $this->dispatchVerificationFailure($provider, $failureReason);

        return $this->rejected();
    }

    /**
     * CHECKPOINT 1 addition (security review Finding 5): dispatches
     * (never synchronously writes) the new verification-failure
     * counter job. Deliberately fire-and-forget from this controller's
     * perspective — a queue failure here must never affect the
     * response already being sent.
     */
    private function dispatchVerificationFailure(string $provider, string $failureReason): void
    {
        RecordWebhookVerificationFailureJob::dispatch($provider, $failureReason);
    }

    private function rejected(): JsonResponse
    {
        return response()->json(['status' => 'rejected'], 401);
    }

    private function errored(): JsonResponse
    {
        return response()->json(['status' => 'error'], 500);
    }

    /**
     * CHECKPOINT 1 addition (design §6): a small, generic,
     * provider-agnostic content-type allowlist check — a shape check,
     * never a provider-identity check. JSON-family content types are
     * accepted for the normal per-event pipeline; `text/plain` is
     * accepted ONLY on the validation-challenge branch (Microsoft
     * Graph's own documented handshake content type). A missing/empty
     * Content-Type header is always accepted on both branches
     * (deliberately lenient — many legitimate webhook senders, and this
     * codebase's own existing test harness, omit it entirely; this
     * check exists to reject a clearly WRONG content type, not to
     * require one).
     */
    private function isAcceptableContentType(?string $contentType, bool $isValidationChallenge): bool
    {
        if ($contentType === null || trim($contentType) === '') {
            return true;
        }

        $normalized = strtolower(trim(explode(';', $contentType)[0]));

        if ($isValidationChallenge) {
            return $normalized === 'text/plain';
        }

        return $normalized === 'application/json' || str_ends_with($normalized, '+json');
    }

    /**
     * CHECKPOINT 1 generalization of the old singleHeaderValue()
     * helper: builds the generic, wire-location-agnostic $headers array
     * every SupportsWebhooksContract method receives, over EVERY
     * request header (not just three hardcoded names). Case-insensitive
     * (Symfony's HeaderBag normalizes header names internally) and
     * duplicate-header-rejecting: a header that appeared more than once
     * (repeated lines or comma-folded) is simply OMITTED from the
     * resulting array — a provider reading `$headers['X'] ?? null`
     * therefore sees the identical "not usable" signal for both
     * "entirely absent" and "appeared more than once," preserving the
     * exact duplicate-header-rejection guarantee the old
     * singleHeaderValue() provided for the three hardcoded headers.
     *
     * @return array<string, string>
     */
    private function extractHeaders(Request $request): array
    {
        $result = [];

        foreach ($request->headers->keys() as $name) {
            $values = $request->headers->all($name);

            if (count($values) === 1) {
                $result[$name] = $values[0];
            }
        }

        return $result;
    }
}
