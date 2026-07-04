<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ManualPaymentRecord — a DETAIL of a canonical payment, never a
 * second payment ledger (project rule 3). One-to-one with Payment
 * (payment_id is unique at the database level); only ever created
 * after PaymentClassificationService has accepted the payment. No own
 * firm_id — scoped transitively through payment_id.
 */
class ManualPaymentRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'received_by',
        'received_at',
        'method_reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
