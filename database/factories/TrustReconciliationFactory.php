<?php

namespace Database\Factories;

use App\Enums\TrustReconciliationStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustAccount;
use App\Models\TrustReconciliation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrustReconciliation>
 */
class TrustReconciliationFactory extends Factory
{
    protected $model = TrustReconciliation::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'trust_account_id' => TrustAccount::factory(),
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
            'system_balance_cents' => 10000,
            'asserted_bank_balance_cents' => 10000,
            'discrepancy_cents' => 0,
            'status' => TrustReconciliationStatus::Balanced,
            'performed_by_firm_user_id' => FirmUser::factory(),
            'completed_at' => now(),
        ];
    }

    public function discrepancy(int $differenceCents = 500): static
    {
        return $this->state(fn (array $attributes) => [
            'asserted_bank_balance_cents' => $attributes['system_balance_cents'] - $differenceCents,
            'discrepancy_cents' => $differenceCents,
            'status' => TrustReconciliationStatus::Discrepancy,
        ]);
    }
}
