<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Integrations\Enums\SyncDirection;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Firm;
use Database\Factories\IntegrationUsageRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IntegrationUsageRecord — raw, append-only, one row per operation
 * (Checkpoint 9, frozen-design-post-security-review.md §2). The ONLY
 * writer is App\Integrations\Services\IntegrationUsageRecorderService.
 *
 * Append-only (mirrors App\Models\AiUsageEvent's exact immutability
 * convention): no `updated_at` column exists on this table
 * (`const UPDATED_AT = null`), and the `booted()` hook throws on any
 * update/delete of an existing row.
 *
 * newFactory() override is REQUIRED (STANDING CONVENTION,
 * checkpoint-00-final-specification.md §6): the default
 * Model::resolveFactoryName() only special-cases the literal
 * `App\Models\` prefix.
 */
class IntegrationUsageRecord extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $table = 'integration_usage_records';

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'provider_key',
        'capability',
        'operation_type',
        'direction',
        'resource_type',
        'quantity',
        'unit',
        'outcome',
        'occurred_at',
        'correlation_id',
        'sync_run_id',
        'sync_item_id',
        'inbound_webhook_event_id',
        'outbox_event_id',
        'idempotency_key',
        'metadata_json',
        'retention_deadline',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('integration_usage_records is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('integration_usage_records is append-only and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'direction' => SyncDirection::class,
            'quantity' => 'integer',
            'occurred_at' => 'datetime',
            'metadata_json' => 'array',
            'retention_deadline' => 'datetime',
        ];
    }

    protected static function newFactory(): IntegrationUsageRecordFactory
    {
        return IntegrationUsageRecordFactory::new();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(IntegrationSyncRun::class, 'sync_run_id');
    }

    public function syncItem(): BelongsTo
    {
        return $this->belongsTo(IntegrationSyncItem::class, 'sync_item_id');
    }

    public function inboundWebhookEvent(): BelongsTo
    {
        return $this->belongsTo(IntegrationInboundWebhookEvent::class, 'inbound_webhook_event_id');
    }

    public function outboxEvent(): BelongsTo
    {
        return $this->belongsTo(IntegrationOutboxEvent::class, 'outbox_event_id');
    }
}
