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
 *
 * expenses now has permanent FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_08_27_950020_prepare_row_level_security_and_
 * force_rls_on_expenses_table.php). query() itself returns an
 * UNEXECUTED Builder — wrapping query()'s own method body would be a
 * no-op with respect to the actual query execution, since the wrap (and
 * its DB::transaction()) would already have closed by the time a
 * caller executes the returned Builder. Instead, totalAmountCents() and
 * list() — the only two execution points that exist anywhere in this
 * codebase today — each wrap their ENTIRE body (the call to query()
 * PLUS the ->sum()/->get() execution) in their own runWithFirmContext()
 * call. query()'s own entitlement check (assertExpensesEnabled(),
 * self-wrapping) stays exactly where it is, unchanged — it now fires
 * once per query() call, which happens inside the new outer wrap here;
 * this is safe because the decoy-wrap hazard is specifically about
 * nesting TWO runWithFirmContext()-opening calls where the inner one's
 * finally clears the outer's context, and that hazard applies to
 * assertExpensesEnabled() itself (already correctly avoided by leaving
 * it as query()'s own first, unwrapped line), not to query() as a
 * whole.
 *
 * Known, deliberately-deferred gap: any future direct caller of
 * query() that executes the returned Builder itself, rather than going
 * through totalAmountCents()/list(), is NOT protected by this fix —
 * there is no way for query() to establish context around a caller
 * that runs .get()/.sum() on the returned Builder several call frames
 * later. Such a caller must establish its own runWithFirmContext()
 * around that execution; this activation does not and cannot enforce
 * that from inside query() itself.
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
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $matterId, $categoryId, $from, $to, $reimbursable, $status) {
            return (int) $this->query($firm, $matterId, $categoryId, $from, $to, $reimbursable, $status)->sum('amount_cents');
        });
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
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $matterId, $categoryId, $from, $to, $reimbursable, $status) {
            return $this->query($firm, $matterId, $categoryId, $from, $to, $reimbursable, $status)->get();
        });
    }
}
