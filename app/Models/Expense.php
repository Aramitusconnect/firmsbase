<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Expense — the firm-owned root of the operating-expense workflow. No
 * trust/IOLTA column of any kind exists on this table (project rule —
 * Phase 12 is operating accounting only). Only ExpenseService may
 * create/edit-while-draft/void a row; only ExpenseApprovalService may
 * move status to Approved/Rejected.
 */
class Expense extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'expense_category_id',
        'vendor_name',
        'amount_cents',
        'currency',
        'expense_date',
        'status',
        'reimbursable',
        'description',
        'created_by_firm_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'reimbursable' => 'boolean',
            'expense_date' => 'date',
            'amount_cents' => 'integer',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'created_by_firm_user_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(ExpenseReceipt::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ExpenseApproval::class);
    }

    /**
     * An expense may accumulate more than one approval decision row
     * over its lifetime (e.g. rejected, then resubmitted and approved).
     * Always read the latest, mirrors Payment::latestClassificationEvent().
     */
    public function latestApproval(): HasOne
    {
        return $this->hasOne(ExpenseApproval::class)->latestOfMany();
    }

    public function matterExpense(): HasOne
    {
        return $this->hasOne(MatterExpense::class);
    }

    public function invoiceLine(): HasOne
    {
        return $this->hasOne(InvoiceLine::class);
    }

    public function isApproved(): bool
    {
        return $this->status === ExpenseStatus::Approved;
    }

    public function isReimbursableAndApproved(): bool
    {
        return $this->reimbursable === true && $this->isApproved();
    }
}
