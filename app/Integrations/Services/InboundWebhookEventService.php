<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\WebhookInboundEventStatus;
use App\Integrations\Models\IntegrationInboundWebhookEvent;
use App\Services\TenantContextService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * InboundWebhookEventService — the ONLY writer of
 * `integration_inbound_webhook_events` (Checkpoint 7,
 * reviews/checkpoint-07/frozen-design-post-security-review.md §10.2).
 * A row is created ONLY after signature verification has succeeded AND
 * the raw body parsed into a normalized event shape with a non-empty,
 * string `provider_event_id` — the caller
 * (App\Integrations\Http\Controllers\InboundWebhookController) is
 * responsible for having already durably recorded the corresponding
 * App\Integrations\Models\IntegrationWebhookReceipt row via
 * App\Integrations\Services\InboundWebhookReceiptService first.
 *
 * Idempotency — TWO independent constraints (frozen design §10.2):
 * `UNIQUE(receipt_id)` and `UNIQUE(firm_integration_id, provider_key,
 * provider_event_id)`. The atomic insert below targets the
 * provider-identity triple (frozen design's required override of the
 * Checkpoint 0 spec's literal §16 text — see the create migration's
 * own docblock) via `INSERT ... ON CONFLICT (firm_integration_id,
 * provider_key, provider_event_id) DO NOTHING RETURNING *`, with a
 * re-SELECT fallback on conflict — mirrors
 * App\Integrations\Services\IntegrationOutboxEventService::recordOnce()'s
 * proven shape. Never check-then-create.
 *
 * Payload minimization (frozen design §13/§16): `payload_reference_json`
 * accepts only an already-sanitized, small, explicitly-named field map
 * — this checkpoint has no per-provider payload-field allowlist
 * builder (unlike `IntegrationOutboxPayloadBuilderService` for outbox
 * events; no such class is in this checkpoint's frozen file allowlist),
 * so the caller is trusted to have ALREADY reduced whatever the
 * provider sent to a minimal, non-secret, non-confidential reference —
 * this method itself never accepts, and structurally cannot accept, a
 * raw Eloquent Model or `$request->all()`-shaped array; its parameter
 * type is a plain `array` the caller must have already sanitized. The
 * TestProvider proof-of-capability call site
 * (App\Integrations\Http\Controllers\InboundWebhookController) passes
 * an empty array by default, since Checkpoint 7 has no real
 * provider/resource mapping to reference yet.
 *
 * Retention (frozen design §13): `retention_deadline` is computed from
 * `config('integrations.webhook.event_redact_after_days', 400)` — the
 * FIRST of the frozen design's two-stage redact-then-delete horizons.
 * This table carries only ONE retention-deadline column (per the
 * create migration's exact column list, §10.2), so the longer
 * delete-after horizon
 * (`config('integrations.webhook.event_delete_after_days', 2555)`) has
 * no column of its own to populate at this checkpoint — it is read
 * here purely so the config key exists and resolves to its documented
 * default, ready for a future purge job (Checkpoint 8+) that will need
 * it; nothing currently persists it. Both are supplied as inline
 * defaults rather than via a config/integrations.php entry — that file
 * is outside this checkpoint's frozen production-file allowlist,
 * mirroring App\Integrations\Services\IntegrationOutboxEventService's
 * established precedent.
 */
final class InboundWebhookEventService
{
    private const DEFAULT_REDACT_AFTER_DAYS = 400;

    private const DEFAULT_DELETE_AFTER_DAYS = 2555;

    public function __construct(private readonly TenantContextService $tenantContext) {}

    /**
     * @param  array<string, mixed>  $sanitizedPayloadReference  already-sanitized, allowlisted field map — never raw provider data.
     * @return array{event: IntegrationInboundWebhookEvent, was_newly_created: bool} `was_newly_created` lets the
     *                                                                               caller (App\Integrations\Http\Controllers\InboundWebhookController) record a firm-attributed
     *                                                                               timeline event via App\Services\TimelineEventRecorder ONLY the first time this exact
     *                                                                               (firm_integration_id, provider_key, provider_event_id) triple is ever seen — a retried/duplicate
     *                                                                               delivery re-selects the SAME existing row and must not spam a second timeline entry.
     */
    public function recordVerifiedEvent(
        int $firmId,
        int $firmIntegrationId,
        int $receiptId,
        string $providerKey,
        string $providerEventId,
        ?string $receiptBodyHash,
        ?string $eventType,
        array $sanitizedPayloadReference,
        Carbon $receivedAt,
    ): array {
        // Note: config('integrations.webhook.event_delete_after_days',
        // self::DEFAULT_DELETE_AFTER_DAYS) is the longer delete-after
        // horizon referenced in this class's own docblock — reserved
        // for a future purge job (Checkpoint 8+); no column exists on
        // this table to persist it against at Checkpoint 7, so it is
        // intentionally not read here. Only the redact-after horizon
        // below is actually persisted, into this table's single
        // retention_deadline column.
        return $this->tenantContext->runWithFirmContext($firmId, function () use (
            $firmId,
            $firmIntegrationId,
            $receiptId,
            $providerKey,
            $providerEventId,
            $receiptBodyHash,
            $eventType,
            $sanitizedPayloadReference,
            $receivedAt,
        ): array {
            $now = now();

            $redactAfterDays = (int) config('integrations.webhook.event_redact_after_days', self::DEFAULT_REDACT_AFTER_DAYS);

            $payloadJson = json_encode($sanitizedPayloadReference, JSON_THROW_ON_ERROR);

            $values = [
                'uuid' => (string) Str::uuid7(),
                'firm_id' => $firmId,
                'firm_integration_id' => $firmIntegrationId,
                'receipt_id' => $receiptId,
                'provider_key' => $providerKey,
                'provider_event_id' => $providerEventId,
                'receipt_body_hash' => $receiptBodyHash,
                'event_type' => $eventType,
                'payload_reference_json' => $payloadJson,
                'payload_hash' => $sanitizedPayloadReference === [] ? null : hash('sha256', $payloadJson),
                'status' => WebhookInboundEventStatus::Verified->value,
                'lock_token' => null,
                'locked_at' => null,
                'processing_attempts' => 0,
                'failure_code' => null,
                'failure_detail' => null,
                'triggering_sync_run_id' => null,
                'received_at' => $receivedAt,
                'started_processing_at' => null,
                'processed_at' => null,
                'terminal_at' => null,
                'retention_deadline' => $now->copy()->addDays($redactAfterDays),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $rows = DB::table('integration_inbound_webhook_events')->insertOrIgnoreReturning(
                $values,
                returning: ['*'],
                uniqueBy: ['firm_integration_id', 'provider_key', 'provider_event_id'],
            );

            $wasNewlyCreated = $rows->isNotEmpty();

            $row = $rows->first() ?? DB::table('integration_inbound_webhook_events')
                ->where('firm_integration_id', $firmIntegrationId)
                ->where('provider_key', $providerKey)
                ->where('provider_event_id', $providerEventId)
                ->first();

            if ($row === null) {
                throw new RuntimeException(
                    'Failed to durably record inbound webhook event (firm_integration_id/provider_key/provider_event_id triple).'
                );
            }

            return [
                'event' => IntegrationInboundWebhookEvent::hydrate([(array) $row])->first(),
                'was_newly_created' => $wasNewlyCreated,
            ];
        });
    }
}
