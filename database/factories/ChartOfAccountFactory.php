<?php

namespace Database\Factories;

use App\Enums\ChartOfAccountType;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChartOfAccount>
 */
class ChartOfAccountFactory extends Factory
{
    protected $model = ChartOfAccount::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'account_code' => $this->faker->unique()->numerify('####'),
            'account_name' => $this->faker->words(2, true),
            'account_type' => ChartOfAccountType::Expense,
            'is_active' => true,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function type(ChartOfAccountType $type): static
    {
        return $this->state(fn () => ['account_type' => $type]);
    }
}
