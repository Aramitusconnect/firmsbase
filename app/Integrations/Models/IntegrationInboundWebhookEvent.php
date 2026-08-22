<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Integrations\Enums\WebhookInboundEventStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Firm;
use Database\Factories\IntegrationInboundWebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IntegrationInboundWebhookEvent — Checkpoint 7
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §10.2).
 * DirectTenant, standard FORCE ROW LEVEL SECURITY (see the companion
 * 2026_09_06_060004_prepare_row_level_security_and_force_rls_on_integration_inbound_webhook_events_table
 * migration). Created only after inbound signature verification has
 * already succeeded — the firm-facing record of a verified inbound
 * webhook delivery.
 *
 * Uses HasPublicUuid (dual-ID design: bigint `id` for FKs, `uuid` for
 * public exposure) — this is a firm-facing activity-log surface, per
 * the create migration's own docblock, matching `FirmIntegration`'s
 * identical convention.
 *
 * `status`/`lock_token`/`locked_at`/`processing_attempts` are intended
 * to be mutated ONLY through the sole-writer
 * App\Integrations\Services\InboundWebhookEventService's guarded
 * atomic UPDATE statements (mirroring
 * App\Integrations\Services\IntegrationOutboxEventService's identical
 * discipline) once a future Checkpoint 8 claim/complete/fail mechanism
 * exists — never via a plain Eloquent ->update() call from any other
 * caller.
 *
 * `payload_reference_json` MUST only ever contain
 * App\Integrations\Data\SanitizedPayloadReference-shaped, allowlisted
 * data — never a raw provider body, never `$request->all()`.
 *
 * newFactory() override is REQUIRED (STANDING CONVENTION,
 * checkpoint-00-final-specification.md §6).
 */
class IntegrationInboundWebhookEvent extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'integration_inbound_webhook_events';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'receipt_id',
        'provider_key',
        'provider_event_id',
        'receipt_body_hash',
        'event_type',
        'payload_reference_json',
        'payload_hash',
        'status',
        'lock_token',
        'locked_at',
        'processing_attempts',
        'failure_code',
        'failure_detail',
        'triggering_sync_run_id',
        'received_at',
        'started_processing_at',
        'processed_at',
        'terminal_at',
        'retention_deadline',
    ];

    protected function casts(): array
    {
        return [
            'payload_reference_json' => 'array',
            'status' => WebhookInboundEventStatus::class,
            'locked_at' => 'datetime',
            'received_at' => 'datetime',
            'started_processing_at' => 'datetime',
            'processed_at' => 'datetime',
            'terminal_at' => 'datetime',
            'retention_deadline' => 'datetime',
        ];
    }

    protected static function newFactory(): IntegrationInboundWebhookEventFactory
    {
        return IntegrationInboundWebhookEventFactory::new();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(IntegrationWebhookReceipt::class, 'receipt_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            WebhookInboundEventStatus::Processed,
            WebhookInboundEventStatus::Failed,
            WebhookInboundEventStatus::Skipped,
        ], true);
    }
}
