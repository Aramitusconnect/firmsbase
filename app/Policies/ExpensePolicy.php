<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;
use App\Services\TimeExpenseAccessPolicyService;

/**
 * ExpensePolicy — mirrors TaskPolicy/DeadlinePolicy's shape. `create()`
 * governs the "+ Add Expense" header action (which calls
 * ExpenseService::create() internally — see CreateExpense's own
 * docblock — never a bare `Expense::create()`).
 *
 * `update()` additionally requires `status === Draft`, matching
 * ExpenseService::editWhileDraft()'s own guard exactly ("Only a draft
 * expense may be edited") — this policy defers entirely to that
 * service's rule rather than re-deriving it, so the two can never
 * drift apart. Status itself is never an editable form field — all
 * transitions are row Actions (SubmitExpenseAction/ApproveExpenseAction/
 * RejectExpenseAction/VoidExpenseAction) calling ExpenseService/
 * ExpenseApprovalService directly.
 *
 * This policy is role-only — the `expenses` module_catalog entitlement
 * gate is a separate, UX-layer concern handled by ExpenseResource's own
 * canAccess()/shouldRegisterNavigation() (mirrors FirmIntegrationResource's
 * split between role-authority Policy and entitlement-authority
 * Resource override), and is re-asserted unconditionally inside every
 * mutating service method regardless (AccountingEntitlementPolicyService::
 * assertExpensesEnabled()) — this Policy is never the sole enforcement
 * point for entitlement.
 */
class ExpensePolicy
{
    public function __construct(
        private readonly TimeExpenseAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canViewExpense($firmUser->role);
    }

    public function view(User $user, Expense $expense): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $expense->firm_id
            && $this->accessPolicy->canViewExpense($firmUser->role);
    }

    public function create(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canManageExpense($firmUser->role);
    }

    public function update(User $user, Expense $expense): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $expense->firm_id
            && $this->accessPolicy->canManageExpense($firmUser->role)
            && $expense->status === ExpenseStatus::Draft;
    }
}
