<?php

namespace App\Models;

use App\Enums\PlatformPaymentAttemptStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PlatformPaymentAttempt — one row per attempt to collect a platform
 * invoice via FakeStripeGateway, including failed attempts. Distinct
 * from PlatformPayment: an invoice can accrue several attempts before
 * (or without ever) producing a succeeded PlatformPayment row.
 */
class PlatformPaymentAttempt extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'billing_account_id',
        'platform_invoice_id',
        'status',
        'attempt_number',
        'gateway_response_code',
        'failure_reason',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlatformPaymentAttemptStatus::class,
            'attempted_at' => 'datetime',
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
}
