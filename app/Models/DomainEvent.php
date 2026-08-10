<?php

namespace App\Models;

use App\Enums\DomainEventProcessingStatus;
use App\Enums\DomainEventType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DomainEvent — Event-Driven Automation Engine, item 1/12. See the
 * create-table migration's own docblock for the full "this IS the
 * transactional outbox, not a queue fed by one" rationale.
 *
 * The FACT portion (event_type, subject_type/id, correlation_id,
 * causation_event_id, causation_depth, payload_json) is immutable after
 * creation — mirrors WebhookDelivery's own established
 * "only named fields may ever be dirty on update" guard. The
 * PROCESSING portion (processing_status, lock_token, locked_at,
 * attempts, max_attempts, next_attempt_at, last_error, processed_at,
 * dead_lettered_at) is exactly what AutomationEventClaimService's
 * SKIP LOCKED claim/complete/fail/dead-letter cycle mutates.
 */
class DomainEvent extends Model
{
    use BelongsToTenant, HasFactory;

    private const MUTABLE_FIELDS = [
        'processing_status', 'lock_token', 'locked_at', 'attempts', 'max_attempts',
        'next_attempt_at', 'last_error', 'processed_at', 'dead_lettered_at',
    ];

    protected $fillable = [
        'firm_id',
        'event_type',
        'subject_type',
        'subject_id',
        'correlation_id',
        'causation_event_id',
        'causation_depth',
        'payload_json',
        'processing_status',
        'lock_token',
        'locked_at',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'last_error',
        'processed_at',
        'dead_lettered_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => DomainEventType::class,
            'subject_id' => 'integer',
            'causation_event_id' => 'integer',
            'causation_depth' => 'integer',
            'payload_json' => 'array',
            'processing_status' => DomainEventProcessingStatus::class,
            'locked_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
            'dead_lettered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $event) {
            $dirtyKeys = array_keys($event->getDirty());
            $disallowed = array_diff($dirtyKeys, self::MUTABLE_FIELDS);

            if (! empty($disallowed)) {
                throw new \LogicException(
                    'domain_events may only update its own processing-state fields ('.implode(', ', self::MUTABLE_FIELDS).'). '.
                    'Disallowed dirty field(s): '.implode(', ', $disallowed)
                );
            }
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function causationEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'causation_event_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class);
    }

    public function isPending(): bool
    {
        return $this->processing_status === DomainEventProcessingStatus::Pending;
    }
}
