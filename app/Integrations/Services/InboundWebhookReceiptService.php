<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\WebhookVerificationOutcome;
use App\Integrations\Models\IntegrationWebhookReceipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * InboundWebhookReceiptService — the ONLY writer of
 * `integration_webhook_receipts` (Checkpoint 7,
 * reviews/checkpoint-07/frozen-design-post-security-review.md §10.1).
 * A receipt row is written ONLY after
 * App\Integrations\Services\WebhookConnectionResolverService::resolveConnectionIdentity()
 * has already resolved a connection AND
 * App\Integrations\Services\InboundWebhookSignatureVerifier::verify()
 * has already returned `true` — every acknowledgment-matrix row that
 * fails BEFORE signature verification succeeds (invalid signature,
 * expired/future timestamp, unsupported provider, disconnected
 * connection, revoked credential) writes NO receipt row at all (frozen
 * design §8.1). The only two outcomes this checkpoint's write path
 * actually persists are `WebhookVerificationOutcome::Verified` (a
 * fully valid, parseable event) and `WebhookVerificationOutcome::Malformed`
 * (signature valid, but the JSON body/`event_id` could not be parsed —
 * frozen design §8.1 row 10). Note that this table's
 * `integration_webhook_receipts_failure_code_required` CHECK constraint
 * IS live and exercised for the `Malformed` case (this service writes
 * `failure_code = 'malformed_payload'` on that row) — it is only the
 * other five failure values gated by that constraint
 * (`signature_invalid`, `routing_unresolved`, `replayed`, `expired`,
 * `error`) that this checkpoint's write path never actually produces,
 * by design, since those pre-verification failures never reach
 * `recordReceipt()` at all (see the acknowledgment-matrix reasoning
 * above).
 *
 * Idempotency (frozen design §10.1): a single atomic
 * `INSERT ... ON CONFLICT (routing_token_hash, body_hash) DO NOTHING
 * RETURNING *` via Laravel's native `insertOrIgnoreReturning()`, with a
 * re-SELECT fallback on conflict — mirrors
 * App\Integrations\Services\IntegrationOutboxEventService::recordOnce()'s
 * exact, proven shape. Never check-then-create. A "valid duplicate"
 * delivery (frozen design §8.1 row 2) transparently resolves to the
 * SAME already-existing row via this fallback.
 */
final class InboundWebhookReceiptService
{
    /**
     * Fallback default for the non-verified receipt-evidence retention
     * window (frozen design §13: "Invalid-request evidence: 7 days").
     */
    private const DEFAULT_RETENTION_DAYS = 7;

    /**
     * Fallback default for the VERIFIED receipt-evidence retention
     * window (frozen design §13: "Verified-receipt evidence: 30 days").
     *
     * CHECKPOINT 8 FIX (agent-8h-architecture-security-review.md §1 item
     * 7 / §2 item 9): prior to this fix, retention_deadline was computed
     * as a single flat 7-day window applied uniformly to EVERY receipt,
     * regardless of verification_outcome — contradicting the frozen
     * 7d/30d split above. This branches the computation on
     * $outcome === WebhookVerificationOutcome::Verified so a verified
     * receipt's stored retention_deadline now correctly reflects the
     * frozen 30-day commitment. Retained as defense-in-depth: the
     * Checkpoint 8 retention sweep independently recomputes eligibility
     * from verification_outcome + received_at at sweep time rather than
     * trusting this column alone (never trust a single layer for a
     * destructive operation).
     */
    private const DEFAULT_VERIFIED_RETENTION_DAYS = 30;

    /**
     * Durably records a receipt for a signature-verified inbound
     * webhook delivery — either a fully valid event ($outcome =
     * Verified) or a valid-signature-but-malformed-payload delivery
     * ($outcome = Malformed, $failureCode required by this table's own
     * `integration_webhook_receipts_failure_code_required` CHECK
     * constraint).
     *
     * Throws \RuntimeException if the durable insert genuinely fails
     * (frozen design §8.1 row 12, "durable-receipt-write failure") —
     * the caller (App\Integrations\Http\Controllers\InboundWebhookController)
     * must translate that into a `500 {"status":"error"}` response,
     * sent BEFORE any `202` acknowledgment, never after.
     */
    public function recordReceipt(
        string $providerKey,
        string $routingTokenHash,
        string $bodyHash,
        WebhookVerificationOutcome $outcome,
        ?string $requestCorrelationId,
        ?string $providerEventId,
        ?string $signatureVersion,
        ?Carbon $providerTimestamp,
        ?string $failureCode,
    ): IntegrationWebhookReceipt {
        $now = now();

        $retentionDays = $outcome === WebhookVerificationOutcome::Verified
            ? (int) config('integrations.webhook.receipt_verified_retention_days', self::DEFAULT_VERIFIED_RETENTION_DAYS)
            : (int) config('integrations.webhook.receipt_retention_days', self::DEFAULT_RETENTION_DAYS);

        $values = [
            'provider_key' => $providerKey,
            'routing_token_hash' => $routingTokenHash,
            'request_correlation_id' => $requestCorrelationId,
            'provider_event_id' => $providerEventId,
            'body_hash' => $bodyHash,
            'signature_version' => $signatureVersion,
            'verification_outcome' => $outcome->value,
            'received_at' => $now,
            'provider_timestamp' => $providerTimestamp,
            'acknowledgment_status' => 'acknowledged',
            'acknowledged_at' => $now,
            'processing_handoff_status' => 'pending',
            'failure_code' => $failureCode,
            'retention_deadline' => $now->copy()->addDays($retentionDays),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $rows = DB::table('integration_webhook_receipts')->insertOrIgnoreReturning(
            $values,
            returning: ['*'],
            uniqueBy: ['routing_token_hash', 'body_hash'],
        );

        $row = $rows->first() ?? DB::table('integration_webhook_receipts')
            ->where('routing_token_hash', $routingTokenHash)
            ->where('body_hash', $bodyHash)
            ->first();

        if ($row === null) {
            throw new RuntimeException('Failed to durably record inbound webhook receipt (routing_token_hash/body_hash pair).');
        }

        return IntegrationWebhookReceipt::hydrate([(array) $row])->first();
    }
}
