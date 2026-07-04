<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\PlatformSubscriptionStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PlatformSubscription — PLATFORM billing only, keyed to
 * billing_account_id. Never keyed to firm_id, never mixed with Phase
 * 3's firm-client PaymentPlan (project rule 1). Not tenant-owned in the
 * firm sense — no BelongsToTenant, no Phase 6 RLS (approved decision).
 */
class PlatformSubscription extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'billing_account_id',
        'plan_id',
        'status',
        'billing_interval',
        'current_period_starts_at',
        'current_period_ends_at',
        'trial_ends_at',
        'cancel_at_period_end',
        'cancelled_at',
        'gateway_subscription_ref',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlatformSubscriptionStatus::class,
            'billing_interval' => BillingInterval::class,
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PlatformSubscriptionItem::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PlatformInvoice::class);
    }
}
