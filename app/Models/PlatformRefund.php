<?php

namespace App\Models;

use App\Enums\PlatformRefundStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformRefund extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'platform_payment_id',
        'status',
        'amount_cents',
        'reason',
        'gateway_refund_ref',
        'requested_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlatformRefundStatus::class,
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PlatformPayment::class, 'platform_payment_id');
    }
}
