<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseReceipt>
 */
class ExpenseReceiptFactory extends Factory
{
    protected $model = ExpenseReceipt::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'expense_id' => Expense::factory(),
            'storage_disk' => 'local',
            'storage_path' => 'expense-receipts/'.$this->faker->uuid().'.pdf',
            'original_filename' => $this->faker->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(1024, 5_000_000),
            'file_hash' => hash('sha256', $this->faker->uuid()),
            'encryption_key_id' => null,
            'uploaded_by_firm_user_id' => null,
        ];
    }

    public function forExpense(Expense $expense): static
    {
        return $this->state(fn () => [
            'firm_id' => $expense->firm_id,
            'expense_id' => $expense->id,
        ]);
    }
}
