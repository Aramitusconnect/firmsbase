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
 *
 * Append-only (mirrors AiApprovalEvent::booted() exactly): no writer
 * service ever updates or deletes an existing row (ExpenseApprovalService
 * only ever calls ExpenseApproval::create()), and this guard now makes
 * that a real, enforced guarantee rather than a merely-conventional
 * one. This is deliberately independent of, and unaffected by, this
 * table's FORCE ROW LEVEL SECURITY policy (see
 * database/migrations/2026_08_27_950022_prepare_row_level_security_and_
 * force_rls_on_expense_approvals_table.php) — that policy's WITH CHECK
 * clause governs INSERT-time firm ownership only, and is explicitly
 * NOT the append-only enforcement mechanism for this table, per this
 * codebase's established ai_approval_events precedent.
 */
class ExpenseApproval extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

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

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('expense_approvals is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('expense_approvals is append-only and cannot be deleted.');
        });
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
