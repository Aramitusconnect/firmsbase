<?php

namespace App\Models;

use App\Enums\PaymentPlanInstallmentStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PaymentPlanInstallment — no own firm_id, tenant isolation flows
 * through payment_plan_id. paid_amount_cents is a CACHE recomputed
 * exclusively by PaymentApplicationService from canonical payments
 * applied against this installment; it never competes with the
 * payments table (project rule 4). Carries a public uuid per approved
 * decision (future portal installment-level links).
 */
class PaymentPlanInstallment extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'payment_plan_id',
        'sequence',
        'amount_cents',
        'due_at',
        'status',
        'paid_amount_cents',
        'paid_at',
        'dunning_state',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'status' => PaymentPlanInstallmentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isFullyPaid(): bool
    {
        return $this->paid_amount_cents >= $this->amount_cents;
    }
}
