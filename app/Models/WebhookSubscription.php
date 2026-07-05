<?php

namespace App\Models;

use App\Enums\WebhookSubscriptionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * WebhookSubscription — the firm-owned root of the Phase 14 webhook
 * foundation. firm_id is non-nullable, so this model uses
 * BelongsToTenant. event_types is validated at write time by
 * WebhookSubscriptionService against WebhookEventTypeRegistry's 11
 * approved cases — never at the DB layer.
 */
class WebhookSubscription extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'event_types',
        'destination_url',
        'status',
        'retry_policy_json',
        'last_delivery_status',
        'created_by_firm_user_id',
    ];

    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'status' => WebhookSubscriptionStatus::class,
            'retry_policy_json' => 'array',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'created_by_firm_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function secrets(): HasMany
    {
        return $this->hasMany(WebhookSecret::class);
    }

    public function activeSecret(): HasOne
    {
        return $this->hasOne(WebhookSecret::class)->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === WebhookSubscriptionStatus::Active;
    }
}
