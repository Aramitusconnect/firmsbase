<?php

namespace App\Models;

use App\Enums\PendingPaymentAllocationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PendingPaymentAllocation — Mixed-Invoice Revenue Allocation pass,
 * item 3. See the create-table migration's own docblock for the full
 * "why this exists, never a second Payment system" rationale.
 * PaymentApplicationService is the only writer of a Pending row;
 * PaymentAllocationResolutionService is the only writer of the
 * Pending -> Resolved transition; OperatingPaymentRefundService/
 * OperatingChargebackService (Pending-Cash Accounting pass) are the
 * only writers of the Pending -> Cancelled transition, when the
 * underlying payment is refunded/charged back in full before its
 * allocation is ever resolved.
 */
class PendingPaymentAllocation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'payment_pending_allocations';

    protected $fillable = [
        'firm_id',
        'payment_id',
        'invoice_id',
        'payment_plan_installment_id',
        'amount_cents',
        'status',
        'reason',
        'resolved_by_firm_user_id',
        'resolved_at',
        'resolved_fee_cents',
        'resolved_cost_cents',
        'resolution_notes',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PendingPaymentAllocationStatus::class,
            'amount_cents' => 'integer',
            'resolved_fee_cents' => 'integer',
            'resolved_cost_cents' => 'integer',
            'resolved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
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

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'resolved_by_firm_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === PendingPaymentAllocationStatus::Pending;
    }

    public function isCancelled(): bool
    {
        return $this->status === PendingPaymentAllocationStatus::Cancelled;
    }
}
