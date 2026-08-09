<?php

namespace App\Models;

use App\Enums\PaymentRequestEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaymentRequestEvent — Payment Link / QR Routing phase. Append-only,
 * mirroring TrustApprovalEvent/AccountingPeriodEvent exactly.
 * actor_firm_user_id is nullable because a payer using the public link
 * is never a FirmUser.
 */
class PaymentRequestEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'payment_request_id',
        'event_type',
        'actor_firm_user_id',
        'amount_cents',
        'provider_transaction_id',
        'provider_response_json',
        'note',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => PaymentRequestEventType::class,
            'amount_cents' => 'integer',
            'provider_response_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('payment_request_events is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('payment_request_events is append-only and cannot be deleted.');
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'actor_firm_user_id');
    }
}
