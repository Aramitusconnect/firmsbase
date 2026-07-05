<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\TrustBalance;
use App\Models\TrustLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrustBalance>
 */
class TrustBalanceFactory extends Factory
{
    protected $model = TrustBalance::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'trust_ledger_id' => TrustLedger::factory(),
            'balance_cents' => 0,
            'last_recomputed_at' => now(),
        ];
    }

    public function forLedger(TrustLedger $ledger): static
    {
        return $this->state(fn () => [
            'firm_id' => $ledger->firm_id,
            'trust_ledger_id' => $ledger->id,
        ]);
    }
}
