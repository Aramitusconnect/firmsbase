<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaymentAllocation — Phase F. Append-only, mirroring
 * AccountingJournalEntry/TrustLedgerEntry exactly: a row is created
 * once and never mutated or deleted. $timestamps = false; only
 * created_at exists.
 */
class PaymentAllocation extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'payment_id',
        'invoice_id',
        'payment_plan_installment_id',
        'amount_cents',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException(
                'payment_allocations is append-only: an existing row can never be updated.'
            );
        });

        static::deleting(function () {
            throw new \LogicException(
                'payment_allocations is append-only: an existing row can never be deleted.'
            );
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
}
