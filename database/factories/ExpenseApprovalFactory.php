<?php

namespace Database\Factories;

use App\Enums\ExpenseApprovalStatus;
use App\Models\Expense;
use App\Models\ExpenseApproval;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseApproval>
 */
class ExpenseApprovalFactory extends Factory
{
    protected $model = ExpenseApproval::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'expense_id' => Expense::factory(),
            'status' => ExpenseApprovalStatus::Pending,
            'decided_by_firm_user_id' => null,
            'decided_at' => null,
            'reason' => null,
        ];
    }

    public function forExpense(Expense $expense): static
    {
        return $this->state(fn () => [
            'firm_id' => $expense->firm_id,
            'expense_id' => $expense->id,
        ]);
    }

    public function status(ExpenseApprovalStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
