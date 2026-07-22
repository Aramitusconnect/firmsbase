<?php

declare(strict_types=1);

namespace App\Integrations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Integrations\Data\ResolvedWebhookConnection;
use App\Integrations\Enums\WebhookVerificationOutcome;
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
use Illuminate\Support\Carbon;
use Throwable;

/**
 * InboundWebhookController — the ONLY HTTP entry point for
 * `POST /webhooks/integrations/{provider}` (Checkpoint 7,
 * reviews/checkpoint-07/frozen-design-post-security-review.md §1/§8).
 * Unauthenticated, no session, no CSRF (see routes/webhooks.php — this
 * route is deliberately registered outside the `web` middleware
 * group). Deliberately thin: every real decision is delegated to the
 * four-step identity-scoped secret-resolution mechanism's dedicated
 * services (§5) — this controller owns only request-shape extraction,
 * call ordering, and translating outcomes into the frozen
 * acknowledgment-matrix response shapes (§8.1).
 *
 * Collapse-to-false (§8): rows 3, 4, 5, 7, 8, 9 of the acknowledgment
 * matrix are byte-identical on the wire (`401 {"status":"rejected"}`).
 * This controller never branches its RESPONSE on which of those cases
 * occurred — only its internal, platform-only audit logging (never
 * exposed to the caller) may vary.
 */
final class InboundWebhookController extends Controller
{
    /**
     * Header name for the internal TestProvider simulation, frozen as
     * the actual value for Checkpoint 7's own test harness (frozen
     * design §1).
     */
    private const SIGNATURE_HEADER = 'X-Test-Provider-Signature';

    private const TIMESTAMP_HEADER = 'X-Test-Provider-Timestamp';

    private const ROUTING_TOKEN_HEADER = 'X-Test-Provider-Connection-Token';

    public function __construct(
        private readonly WebhookConnectionResolverService $resolver,
        private readonly InboundWebhookSignatureVerifier $verifier,
        private readonly InboundWebhookReceiptService $receipts,
        private readonly InboundWebhookEventService $events,
        private readonly InboundWebhookAuditLogger $auditLogger,
        private readonly TimelineEventRecorder $timelineEvents,
    ) {
    }

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        // Raw bytes read FIRST — before any $request->all()/input()/
        // json() call (frozen design §8).
        $rawBody = $request->getContent();

        $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_REQUEST_RECEIVED, [
            'provider' => $provider,
        ]);

        $routingToken = $this->singleHeaderValue($request, self::ROUTING_TOKEN_HEADER);
        $signatureRaw = $this->singleHeaderValue($request, self::SIGNATURE_HEADER);
        $timestampRaw = $this->singleHeaderValue($request, self::TIMESTAMP_HEADER);

        try {
            $resolved = $routingToken === null
                ? null
                : $this->resolver->resolveConnectionIdentity($provider, $routingToken);
        } catch (Throwable) {
            return $this->errored();
        }

        if ($resolved === null) {
            // Early-exit path (unknown provider OR unknown/missing/
            // duplicate routing token) — one indexed lookup. Required
            // timing-oracle mitigation (frozen design §9).
            $this->verifier->performConstantWorkPadding();

            $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_SIGNATURE_REJECTED, [
                'provider' => $provider,
            ]);

            return $this->rejected();
        }

        $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_ROUTE_IDENTITY_RESOLVED, [
            'provider' => $provider,
            'firm_integration_id' => $resolved->firmIntegrationId,
        ]);

        try {
            $candidates = $this->resolver->activeAndPreviousWebhookSecretsFor($resolved);
        } catch (Throwable) {
            return $this->errored();
        }

        if ($candidates === []) {
            // Connection found but not usable (disconnected connection,
            // or a revoked-only credential) — this path already did the
            // real transaction/RLS-scoped read; no extra padding is
            // required (frozen design §9's padding requirement is
            // scoped to the early-exit case above only).
            $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_DISCONNECTED_EVENT_REJECTED, [
                'firm_integration_id' => $resolved->firmIntegrationId,
            ]);

            return $this->rejected();
        }

        if (count($candidates) > 1) {
            $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_SECRET_ROTATION_USED, [
                'firm_integration_id' => $resolved->firmIntegrationId,
            ]);
        }

        $verified = $this->verifier->verify($candidates, $rawBody, $timestampRaw, $signatureRaw);

        // Discard immediately — never logged/persisted/queued beyond
        // this point (frozen design §5 STEP 4).
        unset($candidates);

        if (! $verified) {
            $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_SIGNATURE_REJECTED, [
                'firm_integration_id' => $resolved->firmIntegrationId,
            ]);

            return $this->rejected();
        }

        $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_SIGNATURE_VERIFIED, [
            'firm_integration_id' => $resolved->firmIntegrationId,
        ]);

        return $this->handleVerifiedDelivery($provider, $resolved, $rawBody, $routingToken, $signatureRaw, $timestampRaw);
    }

    private function handleVerifiedDelivery(
        string $provider,
        ResolvedWebhookConnection $resolved,
        string $rawBody,
        string $routingToken,
        ?string $signatureRaw,
        ?string $timestampRaw,
    ): JsonResponse {
        $bodyHash = hash('sha256', $rawBody);
        $routingTokenHash = hash('sha256', $routingToken);
        $signatureVersion = $this->signatureVersionLabel($signatureRaw);
        $providerTimestamp = ($timestampRaw !== null && ctype_digit($timestampRaw))
            ? Carbon::createFromTimestamp((int) $timestampRaw)
            : null;

        // json_decode() happens exactly once, here — ONLY after
        // verification has already succeeded (frozen design §8).
        $decoded = json_decode($rawBody, true);
        $providerEventId = is_array($decoded) ? ($decoded['event_id'] ?? null) : null;
        $eventTypeRaw = is_array($decoded) ? ($decoded['event_type'] ?? null) : null;
        $eventType = is_string($eventTypeRaw) ? $eventTypeRaw : null;

        if (! is_string($providerEventId) || trim($providerEventId) === '') {
            // Malformed payload (frozen design §8.1 row 10): a distinct
            // failure occurring AFTER verification succeeded — never the
            // Str::uuid() fallback the pre-Checkpoint-7 TestProvider
            // stub used. A receipt row IS written (verification
            // genuinely succeeded), but NO event row.
            try {
                $this->receipts->recordReceipt(
                    providerKey: $provider,
                    routingTokenHash: $routingTokenHash,
                    bodyHash: $bodyHash,
                    outcome: WebhookVerificationOutcome::Malformed,
                    requestCorrelationId: null,
                    providerEventId: null,
                    signatureVersion: $signatureVersion,
                    providerTimestamp: $providerTimestamp,
                    failureCode: 'malformed_payload',
                );
            } catch (Throwable) {
                return $this->errored();
            }

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
                providerTimestamp: $providerTimestamp,
                failureCode: null,
            );
        } catch (Throwable) {
            // Durable-receipt-write failure (frozen design §8.1 row 12)
            // — no 202 is ever sent; the sender's own retry logic should
            // treat this as retryable.
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
                // Checkpoint 7 has no per-provider payload-field
                // allowlist builder yet (see
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
        } else {
            $this->auditLogger->record(InboundWebhookAuditLogger::EVENT_DUPLICATE_ACCEPTED, [
                'firm_integration_id' => $resolved->firmIntegrationId,
            ]);
        }

        // Rows 1 and 2 of the acknowledgment matrix are byte-identical
        // on the wire — a valid new event and a valid duplicate both
        // return exactly this response.
        return response()->json(['status' => 'accepted'], 202);
    }

    /**
     * Firm-attributed sink of the frozen design's two-sink audit design
     * (§14) — via the EXISTING, unmodified
     * App\Services\TimelineEventRecorder, only for a genuinely
     * newly-created event row (never on a re-selected duplicate).
     */
    private function recordFirmTimelineEvent(int $firmId, IntegrationInboundWebhookEvent $event): void
    {
        (new TenantContextService())->runWithFirmContext($firmId, function () use ($event): void {
            $this->timelineEvents->record($event->firm, 'integration_webhook.inbound_event_verified', $event, null, [
                'firm_integration_id' => $event->firm_integration_id,
                'provider_key' => $event->provider_key,
            ]);
        });
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
     * Case-insensitive lookup (Symfony's HeaderBag normalizes header
     * names internally) that returns null for BOTH "header entirely
     * absent" and "header appeared more than once" (repeated lines or
     * comma-folded) — frozen design §8's duplicate-header rejection
     * rule, collapsed into the same "no valid value" signal every
     * downstream structural-validation check already treats as
     * invalid.
     */
    private function singleHeaderValue(Request $request, string $name): ?string
    {
        $values = $request->headers->all($name);

        return count($values) === 1 ? $values[0] : null;
    }

    private function signatureVersionLabel(?string $signatureRaw): ?string
    {
        if ($signatureRaw === null || ! str_contains($signatureRaw, '=')) {
            return null;
        }

        return explode('=', $signatureRaw, 2)[0];
    }
}
