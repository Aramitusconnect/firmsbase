<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MatterExpense — no own uuid (accessed only through its parent
 * Matter/Expense). firm_id IS present as a direct column (unlike
 * InvoiceLine) for TenantSafeAccountingPolicyService's defense-in-depth
 * checks, but this model does NOT use BelongsToTenant — mirrors the
 * Phase 8-11 precedent of a firm_id-bearing child row relying on a
 * TenantSafe*PolicyService rather than the trait (e.g. signature_events).
 * reimbursable_snapshot freezes the expense's reimbursable flag at link
 * time so a later category/expense-level change cannot retroactively
 * alter an already-linked expense's invoice-eligibility history. Only
 * MatterExpenseService may write this table.
 */
class MatterExpense extends Model
{
    use HasFactory;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'expense_id',
        'reimbursable_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'reimbursable_snapshot' => 'boolean',
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

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
