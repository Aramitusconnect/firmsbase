<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Integrations\Enums\WebhookVerificationOutcome;
use Database\Factories\IntegrationWebhookReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * IntegrationWebhookReceipt — Checkpoint 7
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §10.1).
 * Platform-owned, pre-tenant. NO RLS at all — see this model's own
 * create migration
 * (database/migrations/2026_09_06_060002_create_integration_webhook_receipts_table.php)
 * for the full "WHY THIS TABLE HAS NO RLS" reasoning. Deliberately does
 * NOT use App\Models\Concerns\BelongsToTenant — this table has no
 * `firm_id`/`firm_integration_id` column at all, and none may ever be
 * added (see the create migration's tenant-blindness note).
 *
 * No `uuid` column, no HasPublicUuid: never externally addressed —
 * `id` is a plain internal bigint PK.
 *
 * Written ONLY by
 * App\Integrations\Services\InboundWebhookReceiptService, via a single
 * atomic `INSERT ... ON CONFLICT (routing_token_hash, body_hash) DO
 * NOTHING RETURNING *` — never a plain Eloquent ->create()/->save()
 * call from any other caller, and never a check-then-create.
 *
 * newFactory() override is REQUIRED (STANDING CONVENTION,
 * checkpoint-00-final-specification.md §6).
 */
class IntegrationWebhookReceipt extends Model
{
    use HasFactory;

    protected $table = 'integration_webhook_receipts';

    protected $fillable = [
        'provider_key',
        'routing_token_hash',
        'request_correlation_id',
        'provider_event_id',
        'body_hash',
        'signature_version',
        'verification_outcome',
        'received_at',
        'provider_timestamp',
        'acknowledgment_status',
        'acknowledged_at',
        'processing_handoff_status',
        'failure_code',
        'retention_deadline',
    ];

    protected $hidden = [
        'routing_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'verification_outcome' => WebhookVerificationOutcome::class,
            'received_at' => 'datetime',
            'provider_timestamp' => 'datetime',
            'acknowledged_at' => 'datetime',
            'retention_deadline' => 'datetime',
        ];
    }

    protected static function newFactory(): IntegrationWebhookReceiptFactory
    {
        return IntegrationWebhookReceiptFactory::new();
    }
}
