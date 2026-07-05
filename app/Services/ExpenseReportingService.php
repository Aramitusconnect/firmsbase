<?php

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ExpenseReportingService — pure, read-only aggregation. No new table
 * is introduced for reporting (no dedicated report-storage table
 * exists anywhere in this codebase, consistent with every prior
 * phase). Every method is gated on the expenses entitlement first
 * (correction #6 — reporting is blocked when Expenses are disabled).
 */
class ExpenseReportingService
{
    public function __construct(private readonly AccountingEntitlementPolicyService $entitlementPolicy)
    {
    }

    public function query(
        Firm $firm,
        ?int $matterId = null,
        ?int $categoryId = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?bool $reimbursable = null,
        ?ExpenseStatus $status = null,
    ): Builder {
        $this->entitlementPolicy->assertExpensesEnabled($firm);

        $query = Expense::query()->where('firm_id', $firm->id);

        if ($matterId !== null) {
            $query->where('matter_id', $matterId);
        }

        if ($categoryId !== null) {
            $query->where('expense_category_id', $categoryId);
        }

        if ($from !== null) {
            $query->where('expense_date', '>=', $from);
        }

        if ($to !== null) {
            $query->where('expense_date', '<=', $to);
        }

        if ($reimbursable !== null) {
            $query->where('reimbursable', $reimbursable);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query;
    }

    public function totalAmountCents(
        Firm $firm,
        ?int $matterId = null,
        ?int $categoryId = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?bool $reimbursable = null,
        ?ExpenseStatus $status = null,
    ): int {
        return (int) $this->query($firm, $matterId, $categoryId, $from, $to, $reimbursable, $status)->sum('amount_cents');
    }

    public function list(
        Firm $firm,
        ?int $matterId = null,
        ?int $categoryId = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?bool $reimbursable = null,
        ?ExpenseStatus $status = null,
    ): Collection {
        return $this->query($firm, $matterId, $categoryId, $from, $to, $reimbursable, $status)->get();
    }
}
