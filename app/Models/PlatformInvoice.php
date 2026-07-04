<?php

namespace App\Models;

use App\Enums\PlatformInvoiceStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PlatformInvoice — PLATFORM billing only. Deliberately separate from
 * Phase 3's Invoice (firm-client billing) — different table, different
 * enum, never mixed (project rule 1).
 */
class PlatformInvoice extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'billing_account_id',
        'platform_subscription_id',
        'status',
        'period_starts_at',
        'period_ends_at',
        'subtotal_cents',
        'tax_cents',
        'total_cents',
        'due_at',
        'paid_at',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlatformInvoiceStatus::class,
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PlatformSubscription::class, 'platform_subscription_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PlatformInvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PlatformPayment::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PlatformPaymentAttempt::class);
    }

    public function isPaid(): bool
    {
        return $this->status === PlatformInvoiceStatus::Paid;
    }
}
