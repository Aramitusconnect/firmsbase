<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Integrations\Enums\OutboxEventStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use Database\Factories\IntegrationOutboxEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IntegrationOutboxEvent — the transactional-outbox row (Checkpoint 6,
 * reviews/checkpoint-06/frozen-design-post-review.md §6/§7/§11;
 * agent-6d-outbox-claiming.md). Direct firm-owned. `status`/
 * `lock_token`/`locked_at`/`attempts` are mutated ONLY through the
 * sole-writer IntegrationOutboxEventService's guarded atomic UPDATE
 * statements (claim/complete/release/fail/deadLetter/cancel) — never
 * via a plain Eloquent ->update() call on this model, and never
 * outside those exact SQL shapes (frozen-design-post-review.md §7).
 *
 * `payload_json` MUST only ever contain
 * App\Integrations\Data\SanitizedPayloadReference::toArray()'s output —
 * no code path on this model or its writing service accepts a raw
 * Eloquent Model.
 *
 * newFactory() override is REQUIRED (STANDING CONVENTION,
 * checkpoint-00-final-specification.md §6).
 */
class IntegrationOutboxEvent extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'integration_outbox_events';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'domain_event_id',
        'event_type',
        'resource_type',
        'resource_id',
        'payload_json',
        'payload_hash',
        'status',
        'lock_token',
        'locked_at',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'last_error',
        'completed_at',
        'dead_lettered_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'status' => OutboxEventStatus::class,
            'locked_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'completed_at' => 'datetime',
            'dead_lettered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function newFactory(): IntegrationOutboxEventFactory
    {
        return IntegrationOutboxEventFactory::new();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            OutboxEventStatus::Completed,
            OutboxEventStatus::DeadLettered,
            OutboxEventStatus::Cancelled,
        ], true);
    }
}
