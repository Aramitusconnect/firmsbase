<?php

namespace App\Models;

use App\Enums\ExpenseApprovalStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ExpenseApproval — written exclusively by ExpenseApprovalService. The
 * approver role set (FirmOwner, BillingStaff only — correction #5) is
 * enforced at the service layer, not here.
 */
class ExpenseApproval extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'expense_id',
        'status',
        'decided_by_firm_user_id',
        'decided_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExpenseApprovalStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'decided_by_firm_user_id');
    }
}
