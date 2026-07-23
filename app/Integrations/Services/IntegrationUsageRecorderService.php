<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Data\SanitizedUsageMetadataReference;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Models\IntegrationUsageRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * IntegrationUsageRecorderService — the ONLY writer of
 * `integration_usage_records` (Checkpoint 9, frozen-design-post-
 * security-review.md §2; agent-9h-architecture-security-review.md §1).
 * Raw, append-only, one row per operation — mirrors
 * `App\Integrations\Services\IntegrationOutboxEventService::recordOnce()`'s
 * exact idempotent-insert discipline.
 *
 * $metadata MUST already be a SanitizedUsageMetadataReference — this
 * method's signature structurally cannot accept a raw Eloquent Model, a
 * raw provider response, or `$request->all()`.
 *
 * $idempotencyKey MUST be derived by the caller as
 * `"{source_type}:{source_id}"`, extended with a documented
 * deterministic suffix (`:{unit}` or `:{capability}`) only when one
 * source operation legitimately produces more than one usage row
 * (frozen design §2) — see deriveIdempotencyKey() below, a pure helper
 * callers may use to construct it consistently.
 *
 * `retention_deadline` is computed HERE, at insert time, in PHP — never
 * a DB default/trigger. `integrations.usage_records.retention_days`
 * ships with NO default (agent-9h-architecture-security-review.md
 * §6.3); when the config key resolves to null, `retention_deadline` is
 * left null rather than guessing a number — any future sweep method
 * must check for null and no-op with a disclosed log event, exactly
 * mirroring the `oauth_states.unconsumed_expired_retention_hours`
 * precedent, never inventing a value here.
 *
 * No caller in this checkpoint's own file allowlist invokes this
 * service yet (frozen design explicitly scopes wiring it into
 * sync/webhook/outbox call sites to a later checkpoint) — this class
 * is required, reviewed infrastructure, not dead code: it is the sole
 * legal write path the moment a future checkpoint starts recording
 * usage evidence.
 */
final class IntegrationUsageRecorderService
{
    /**
     * Idempotent, atomic write via `insertOrIgnoreReturning()` + a
     * re-SELECT fallback, mirroring
     * `IntegrationOutboxEventService::recordOnce()` exactly — never
     * throws on a legitimate retry with the SAME `$idempotencyKey`; the
     * caller always gets back the durable row either way.
     */
    public function recordOnce(
        int $firmId,
        int $firmIntegrationId,
        string $providerKey,
        string $capability,
        string $operationType,
        SyncDirection $direction,
        ?ResourceType $resourceType,
        string $unit,
        string $outcome,
        string $idempotencyKey,
        ?Carbon $occurredAt = null,
        int $quantity = 1,
        ?SanitizedUsageMetadataReference $metadata = null,
        ?int $syncRunId = null,
        ?int $syncItemId = null,
        ?int $inboundWebhookEventId = null,
        ?int $outboxEventId = null,
        ?string $correlationId = null,
    ): IntegrationUsageRecord {
        $metadataArray = $metadata?->toArray() ?? [];
        $retentionDeadline = $this->computeRetentionDeadline();

        $rows = DB::table('integration_usage_records')->insertOrIgnoreReturning(
            [
                'uuid' => (string) Str::uuid7(),
                'firm_id' => $firmId,
                'firm_integration_id' => $firmIntegrationId,
                'provider_key' => $providerKey,
                'capability' => $capability,
                'operation_type' => $operationType,
                'direction' => $direction->value,
                'resource_type' => $resourceType?->value,
                'quantity' => $quantity,
                'unit' => $unit,
                'outcome' => $outcome,
                'occurred_at' => $occurredAt ?? now(),
                'correlation_id' => $correlationId,
                'sync_run_id' => $syncRunId,
                'sync_item_id' => $syncItemId,
                'inbound_webhook_event_id' => $inboundWebhookEventId,
                'outbox_event_id' => $outboxEventId,
                'idempotency_key' => $idempotencyKey,
                'metadata_json' => json_encode($metadataArray, JSON_THROW_ON_ERROR),
                'retention_deadline' => $retentionDeadline,
                'created_at' => now(),
            ],
            returning: ['*'],
            uniqueBy: ['firm_integration_id', 'idempotency_key'],
        );

        $row = $rows->first() ?? DB::table('integration_usage_records')
            ->where('firm_integration_id', $firmIntegrationId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        return IntegrationUsageRecord::hydrate([(array) $row])->first();
    }

    /**
     * Frozen derivation rule (frozen design §2): `"{source_type}:{source_id}"`,
     * extended with a documented deterministic suffix only when one
     * source operation legitimately produces more than one usage row.
     * Pure, no I/O — callers remain responsible for choosing
     * `$sourceType`/`$sourceId` correctly (e.g. `sync_item`/$item->id,
     * `outbox_event`/$event->id, `inbound_webhook_event`/$event->id).
     */
    public function deriveIdempotencyKey(string $sourceType, string $sourceId, ?string $suffix = null): string
    {
        $key = "{$sourceType}:{$sourceId}";

        return $suffix === null ? $key : "{$key}:{$suffix}";
    }

    /**
     * NO DEFAULT (agent-9h-architecture-security-review.md §6.3) — the
     * correct fail-safe treatment for a table with no prior retention
     * anchor. Returns null, never a guessed number, when the config key
     * is unset.
     */
    private function computeRetentionDeadline(): ?Carbon
    {
        $retentionDays = config('integrations.usage_records.retention_days');

        if ($retentionDays === null) {
            return null;
        }

        return now()->addDays((int) $retentionDays);
    }
}
