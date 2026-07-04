<?php

namespace App\Models;

use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Payment — THE canonical payment table (project rule: reusable by
 * Phase 6 Stripe flows and Phase 13 trust accounting). A row exists
 * for every attempt, including blocked ones — status can never move
 * from Blocked to Succeeded. payment_classification is the strict
 * PaymentClassification enum, set only by PaymentClassificationService
 * — no other code may write to this column.
 */
class Payment extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'client_id',
        'matter_id',
        'invoice_id',
        'payment_plan_installment_id',
        'amount_cents',
        'currency',
        'payment_method',
        'payment_classification',
        'status',
        'external_reference',
        'idempotency_key',
        'rejection_reason',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_method' => ManualPaymentMethod::class,
            'payment_classification' => PaymentClassification::class,
            'status' => PaymentStatus::class,
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentPlanInstallment(): BelongsTo
    {
        return $this->belongsTo(PaymentPlanInstallment::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function manualPaymentRecord(): HasOne
    {
        return $this->hasOne(ManualPaymentRecord::class);
    }

    /**
     * Phase 3 creates exactly one classification event per payment;
     * latestOfMany() is defensive in case a future phase re-classifies
     * (e.g. a chargeback reversal) and appends a second event row.
     */
    public function latestClassificationEvent(): HasOne
    {
        return $this->hasOne(PaymentClassificationEvent::class)->latestOfMany();
    }

    public function isAcceptedOperatingPayment(): bool
    {
        return $this->payment_classification === PaymentClassification::OperatingPayment
            && $this->status === PaymentStatus::Succeeded;
    }
}
