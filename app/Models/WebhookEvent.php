<?php

namespace App\Models;

use App\Enums\WebhookEventType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WebhookEvent — append-only (correction #13): no updated_at, and the
 * model's booted() hook throws on any update/delete of an existing row,
 * mirroring TrustApprovalEvent/TrustLedgerEntry's exact immutability
 * pattern. One row per business event; fans out to N webhook_deliveries
 * (one per matching active subscription), never one event row per
 * subscription (correction #11). payload_json is already the minimized,
 * allowlisted payload built by WebhookPayloadBuilderService.
 */
class WebhookEvent extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'event_type',
        'subject_type',
        'subject_id',
        'payload_json',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => WebhookEventType::class,
            'payload_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('webhook_events is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('webhook_events is append-only and cannot be deleted.');
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function subject(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
