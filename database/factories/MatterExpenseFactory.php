<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterExpense>
 */
class MatterExpenseFactory extends Factory
{
    protected $model = MatterExpense::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => Matter::factory(),
            'expense_id' => Expense::factory(),
            'reimbursable_snapshot' => false,
        ];
    }

    public function forExpenseAndMatter(Expense $expense, Matter $matter): static
    {
        return $this->state(fn () => [
            'firm_id' => $expense->firm_id,
            'matter_id' => $matter->id,
            'expense_id' => $expense->id,
            'reimbursable_snapshot' => $expense->reimbursable,
        ]);
    }
}
