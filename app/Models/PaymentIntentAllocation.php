<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentDestinationClass;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaymentIntentAllocation — FirmsVault Pay Gate A2. How a
 * PaymentIntent's amount is divided between the Operating and Trust
 * sides (v1.4 §18).
 *
 * Append-only, mirroring payment_allocations / accounting_journal_entries
 * exactly: a row is created once and never mutated or deleted. This is
 * what makes the completeness invariant
 * (SUM(allocations) = intent.amount_cents), established atomically at
 * freeze time, stay true forever afterwards — there is no code path
 * that can shift an allocation out from under a frozen intent.
 *
 * $timestamps = false; only created_at exists.
 */
class PaymentIntentAllocation extends Model
{
    use BelongsToTenant, HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'payment_intent_id',
        'destination_class',
        'amount_cents',
        'invoice_id',
        'matter_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'destination_class' => PaymentDestinationClass::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException(
                'payment_intent_allocations is append-only: an existing row can never be updated.'
            );
        });

        static::deleting(function () {
            throw new \LogicException(
                'payment_intent_allocations is append-only: an existing row can never be deleted.'
            );
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }
}
