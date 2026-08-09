<?php

namespace App\Models;

use App\Enums\PaymentReversalType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaymentReversal — Phase G. Append-only, mirroring PaymentAllocation/
 * AccountingJournalEntry exactly.
 */
class PaymentReversal extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'payment_id',
        'invoice_id',
        'payment_plan_installment_id',
        'reversal_type',
        'amount_cents',
        'reason',
        'actor_firm_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'reversal_type' => PaymentReversalType::class,
            'amount_cents' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('payment_reversals is append-only: an existing row can never be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('payment_reversals is append-only: an existing row can never be deleted.');
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentPlanInstallment(): BelongsTo
    {
        return $this->belongsTo(PaymentPlanInstallment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'actor_firm_user_id');
    }
}
