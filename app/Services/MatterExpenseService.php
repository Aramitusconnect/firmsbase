<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterExpense;

/**
 * MatterExpenseService — the only writer of matter_expenses. Enforces
 * same-firm (matter and expense must belong to the same firm — required
 * test) and freezes expense.reimbursable into reimbursable_snapshot at
 * link time, so a later category/expense-level change cannot
 * retroactively alter an already-linked expense's invoice-eligibility
 * history.
 *
 * matter_expenses now has permanent FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_08_27_950012_prepare_row_level_security_and_
 * force_rls_on_matter_expenses_table.php), so the duplicate-guard read
 * (`$expense->matterExpense()->exists()`) and the MatterExpense::create()
 * write below must both run under the target firm's app.current_firm_id
 * database session setting, or they will see zero rows / be rejected by
 * the policy's WITH CHECK clause respectively.
 */
class MatterExpenseService
{
    public function __construct(
        private readonly AccountingEntitlementPolicyService $entitlementPolicy,
        private readonly TenantSafeAccountingPolicyService $tenantSafePolicy,
    ) {}

    public function link(Firm $firm, Matter $matter, Expense $expense): MatterExpense
    {
        // assertExpensesEnabled() stays OUTSIDE the context wrap below —
        // not because it is "PHP-only" (it isn't: it queries
        // firm_entitlements via EntitlementService::isEnabled()/resolve())
        // but because resolve() already self-wraps its entire body in its
        // own runWithFirmContext() call (see EntitlementService's own
        // docblock). Nesting this call inside another active
        // runWithFirmContext() would trigger the documented "decoy wrap"
        // hazard: the inner wrap's finally block restores the context to
        // whatever was active immediately before IT was entered, which
        // would clear/replace the outer wrap's context the moment the
        // inner call returns, rather than leaving the outer context
        // active for the rest of this method. The other checks below
        // (assertExpenseBelongsToFirm, the inline matter/firm comparison,
        // assertMatterAndExpenseShareFirm) also stay outside — they are
        // genuinely pure in-memory property comparisons against
        // already-loaded models, with no database access of their own.
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseBelongsToFirm($expense, $firm);

        if ($matter->firm_id !== $firm->id) {
            throw new \RuntimeException('Matter does not belong to this firm.');
        }

        $this->tenantSafePolicy->assertMatterAndExpenseShareFirm($matter, $expense);

        // The duplicate-guard read and the create() write must observe
        // the SAME transactional context, so both are wrapped together
        // in one outer call rather than wrapping each individually (or
        // wrapping only create()'s arguments) — a duplicate check that
        // ran under one context and a write that ran under another could
        // race or, worse, mask the FORCE RLS-driven zero-rows-visible
        // failure mode as a false "not linked yet" result.
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $matter, $expense) {
            if ($expense->matterExpense()->exists()) {
                throw new \RuntimeException('This expense is already linked to a matter.');
            }

            return MatterExpense::create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'expense_id' => $expense->id,
                'reimbursable_snapshot' => $expense->reimbursable,
            ]);
        });
    }
}
