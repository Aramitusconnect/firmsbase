<?php

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;

/**
 * ExpenseService — the only creator/mutator of expenses rows. Only a
 * Draft expense may be edited or submitted; Approved/Rejected are set
 * exclusively by ExpenseApprovalService, never here. This table has no
 * trust/IOLTA column of any kind (project rule).
 *
 * expenses now has permanent FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_08_27_950020_prepare_row_level_security_and_
 * force_rls_on_expenses_table.php), so every real DB read/write below
 * runs inside its own runWithFirmContext() call. The entitlement check
 * (assertExpensesEnabled()) and every pure in-memory
 * TenantSafeAccountingPolicyService::assert*() call / inline property
 * comparison stay OUTSIDE every wrap, called first, unchanged —
 * assertExpensesEnabled() self-wraps its own entire body via
 * EntitlementService::isEnabled()/resolve(), and nesting it inside
 * another active runWithFirmContext() call would trigger the
 * documented "decoy wrap" hazard (the inner call's finally would clear
 * the outer wrap's context the moment it returns).
 */
class ExpenseService
{
    public function __construct(
        private readonly AccountingEntitlementPolicyService $entitlementPolicy,
        private readonly TenantSafeAccountingPolicyService $tenantSafePolicy,
    ) {}

    public function create(
        Firm $firm,
        ExpenseCategory $category,
        FirmUser $createdBy,
        string $vendorName,
        int $amountCents,
        \DateTimeInterface $expenseDate,
        bool $reimbursable = false,
        ?Matter $matter = null,
        ?string $description = null,
        string $currency = 'usd',
    ): Expense {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseCategoryBelongsToFirm($category, $firm);

        if ($matter && $matter->firm_id !== $firm->id) {
            throw new \RuntimeException('Matter does not belong to this firm.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, fn () => Expense::create([
            'firm_id' => $firm->id,
            'matter_id' => $matter?->id,
            'expense_category_id' => $category->id,
            'vendor_name' => $vendorName,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'expense_date' => $expenseDate,
            'status' => ExpenseStatus::Draft,
            'reimbursable' => $reimbursable,
            'description' => $description,
            'created_by_firm_user_id' => $createdBy->id,
        ]));
    }

    public function submit(Firm $firm, Expense $expense): Expense
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseBelongsToFirm($expense, $firm);

        if ($expense->status !== ExpenseStatus::Draft) {
            throw new \RuntimeException('Only a draft expense may be submitted.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($expense) {
            $expense->update(['status' => ExpenseStatus::Submitted]);

            return $expense->fresh();
        });
    }

    public function editWhileDraft(Firm $firm, Expense $expense, array $attributes): Expense
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseBelongsToFirm($expense, $firm);

        if ($expense->status !== ExpenseStatus::Draft) {
            throw new \RuntimeException('Only a draft expense may be edited.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($expense, $attributes) {
            $expense->update(array_intersect_key($attributes, array_flip([
                'vendor_name', 'amount_cents', 'currency', 'expense_date',
                'reimbursable', 'description', 'expense_category_id', 'matter_id',
            ])));

            return $expense->fresh();
        });
    }

    public function void(Firm $firm, Expense $expense): Expense
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseBelongsToFirm($expense, $firm);

        if ($expense->status === ExpenseStatus::Voided) {
            throw new \RuntimeException('Expense is already voided.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($expense) {
            $expense->update(['status' => ExpenseStatus::Voided]);

            return $expense->fresh();
        });
    }
}
