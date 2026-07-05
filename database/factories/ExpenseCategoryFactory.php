<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'chart_of_accounts_id' => null,
            'name' => $this->faker->unique()->words(2, true),
            'is_active' => true,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
