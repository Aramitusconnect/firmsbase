<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterTrustBalance;
use App\Models\TrustLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterTrustBalance>
 */
class MatterTrustBalanceFactory extends Factory
{
    protected $model = MatterTrustBalance::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'trust_ledger_id' => TrustLedger::factory(),
            'matter_id' => Matter::factory(),
            'balance_cents' => 0,
            'last_recomputed_at' => now(),
        ];
    }

    public function forLedgerAndMatter(TrustLedger $ledger, Matter $matter): static
    {
        return $this->state(fn () => [
            'firm_id' => $ledger->firm_id,
            'trust_ledger_id' => $ledger->id,
            'matter_id' => $matter->id,
        ]);
    }
}
