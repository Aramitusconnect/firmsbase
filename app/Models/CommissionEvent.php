<?php

namespace App\Models;

use App\Enums\CommissionEventStatus;
use App\Enums\CommissionEventType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * CommissionEvent — keyed to billing_account_id and Phase 6 platform
 * billing records ONLY (platform_invoice_id/platform_payment_id).
 * Deliberately has no relation to Invoice/Payment/PaymentPlan/
 * ManualPaymentRecord (Phase 3 firm-client billing) — project rule:
 * commission must never reference firm-client invoices/payments.
 */
class CommissionEvent extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'commission_plan_id',
        'billing_account_id',
        'platform_admin_id',
        'platform_invoice_id',
        'platform_payment_id',
        'attributable_type',
        'attributable_id',
        'event_type',
        'status',
        'amount_cents',
        'holding_period_ends_at',
        'blocked_reason',
        'reversed_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => CommissionEventType::class,
            'status' => CommissionEventStatus::class,
            'amount_cents' => 'integer',
            'holding_period_ends_at' => 'datetime',
            'reversed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function commissionPlan(): BelongsTo
    {
        return $this->belongsTo(CommissionPlan::class);
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function platformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class);
    }

    public function platformInvoice(): BelongsTo
    {
        return $this->belongsTo(PlatformInvoice::class);
    }

    public function platformPayment(): BelongsTo
    {
        return $this->belongsTo(PlatformPayment::class);
    }

    public function attributable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPayable(): bool
    {
        return $this->status === CommissionEventStatus::Payable;
    }
}
