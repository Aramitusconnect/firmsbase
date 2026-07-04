<?php

namespace App\Models;

use App\Enums\PlatformPaymentStatus;
use App\Enums\PaymentClassification;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PlatformPayment — PLATFORM billing only. classification reuses the
 * EXISTING PaymentClassification enum (Phase 3) but is always written
 * as OperatingPayment by PlatformBillingClassificationService — never
 * TrustIoltaPayment (no trust money moves through platform billing,
 * project rule 9) and never persisted here as BlockedPayment (a
 * blocked classification means no PaymentIntent is simulated at all —
 * see PlatformPaymentService).
 */
class PlatformPayment extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'billing_account_id',
        'platform_invoice_id',
        'status',
        'classification',
        'amount_cents',
        'gateway_payment_ref',
        'attempted_at',
        'succeeded_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlatformPaymentStatus::class,
            'classification' => PaymentClassification::class,
            'attempted_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PlatformInvoice::class, 'platform_invoice_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PlatformRefund::class);
    }

    public function isSucceeded(): bool
    {
        return $this->status === PlatformPaymentStatus::Succeeded;
    }
}
