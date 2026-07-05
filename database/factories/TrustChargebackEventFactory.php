<?php

namespace Database\Factories;

use App\Enums\TrustChargebackStatus;
use App\Models\Firm;
use App\Models\TrustChargebackEvent;
use App\Models\TrustLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrustChargebackEvent>
 */
class TrustChargebackEventFactory extends Factory
{
    protected $model = TrustChargebackEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'original_trust_ledger_entry_id' => TrustLedgerEntry::factory(),
            'amount_cents' => 10000,
            'reason' => 'Client disputed the deposit with their card issuer.',
            'status' => TrustChargebackStatus::Reported,
            'reported_at' => now(),
        ];
    }

    public function reversed(): static
    {
        return $this->state(fn () => [
            'status' => TrustChargebackStatus::Reversed,
            'reversal_trust_ledger_entry_id' => TrustLedgerEntry::factory(),
        ]);
    }
}
