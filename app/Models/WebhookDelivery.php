<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WebhookDelivery — no BelongsToTenant (scoped transitively through
 * webhook_subscription_id/webhook_event_id, defended by
 * TenantSafeWebhookPolicyService — same reasoning as
 * MatterTrustBalance in Phase 13). This is the one Phase 14 row allowed
 * to mutate after creation, but ONLY on the exact fields named in
 * correction #13: status, attempt_count, next_attempt_at,
 * last_attempted_at. The booted() guard below throws if any other
 * column is dirty on an update — including the replay-lineage columns,
 * which are set exactly once, at creation, by WebhookReplayService, and
 * never touched again.
 */
class WebhookDelivery extends Model
{
    use HasFactory, HasPublicUuid;

    private const MUTABLE_FIELDS = ['status', 'attempt_count', 'next_attempt_at', 'last_attempted_at'];

    protected $fillable = [
        'firm_id',
        'webhook_subscription_id',
        'webhook_event_id',
        'status',
        'attempt_count',
        'next_attempt_at',
        'last_attempted_at',
        'replayed_from_delivery_id',
        'replayed_by_firm_user_id',
        'replayed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WebhookDeliveryStatus::class,
            'attempt_count' => 'integer',
            'next_attempt_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'replayed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $delivery) {
            $dirtyKeys = array_keys($delivery->getDirty());
            $disallowed = array_diff($dirtyKeys, self::MUTABLE_FIELDS);

            if (! empty($disallowed)) {
                throw new \LogicException(
                    'webhook_deliveries may only update status/attempt_count/next_attempt_at/last_attempted_at. '.
                    'Disallowed dirty field(s): '.implode(', ', $disallowed)
                );
            }
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class, 'webhook_event_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(WebhookDeliveryAttempt::class);
    }

    public function replayedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replayed_from_delivery_id');
    }

    public function replayedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'replayed_by_firm_user_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [WebhookDeliveryStatus::Delivered, WebhookDeliveryStatus::Exhausted], true);
    }
}
