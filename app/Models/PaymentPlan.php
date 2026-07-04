<?php

namespace App\Models;

use App\Enums\PaymentPlanStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PaymentPlan — a schedule, never a parallel ledger (project rule).
 * total_cents is the sum of installment amounts, not a running
 * balance. All status transitions (create/edit/activate/renegotiate/
 * cancel/complete/default) live exclusively in PaymentPlanService.
 */
class PaymentPlan extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'client_id',
        'matter_id',
        'invoice_id',
        'status',
        'total_cents',
        'currency',
        'installment_count',
        'supersedes_payment_plan_id',
        'activated_at',
        'renegotiated_at',
        'completed_at',
        'defaulted_at',
        'cancelled_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentPlanStatus::class,
            'activated_at' => 'datetime',
            'renegotiated_at' => 'datetime',
            'completed_at' => 'datetime',
            'defaulted_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_payment_plan_id');
    }

    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_payment_plan_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(PaymentPlanInstallment::class)->orderBy('sequence');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentPlanEvent::class);
    }

    public function isEditable(): bool
    {
        return $this->status === PaymentPlanStatus::Draft;
    }

    public function isDunningEligibleStatus(): bool
    {
        return $this->status === PaymentPlanStatus::Active;
    }
}
