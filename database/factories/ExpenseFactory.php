<?php

namespace Database\Factories;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\FirmUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => null,
            'expense_category_id' => ExpenseCategory::factory(),
            'vendor_name' => $this->faker->company(),
            'amount_cents' => $this->faker->numberBetween(500, 50000),
            'currency' => 'usd',
            'expense_date' => now()->subDays($this->faker->numberBetween(0, 30)),
            'status' => ExpenseStatus::Draft,
            'reimbursable' => false,
            'description' => $this->faker->sentence(),
            'created_by_firm_user_id' => FirmUser::factory(),
        ];
    }

    /**
     * Ties the expense AND its nested category to the given firm.
     */
    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'expense_category_id' => ExpenseCategory::factory()->forFirm($firm),
            'created_by_firm_user_id' => FirmUser::factory()->forFirm($firm),
        ]);
    }

    public function reimbursable(bool $reimbursable = true): static
    {
        return $this->state(fn () => ['reimbursable' => $reimbursable]);
    }

    public function status(ExpenseStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
