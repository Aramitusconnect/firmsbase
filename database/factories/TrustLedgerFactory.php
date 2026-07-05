<?php

namespace Database\Factories;

use App\Enums\TrustLedgerStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\TrustAccount;
use App\Models\TrustLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrustLedger>
 */
class TrustLedgerFactory extends Factory
{
    protected $model = TrustLedger::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'trust_account_id' => TrustAccount::factory(),
            'client_id' => Client::factory(),
            'status' => TrustLedgerStatus::Active,
        ];
    }

    public function frozen(): static
    {
        return $this->state(fn () => ['status' => TrustLedgerStatus::Frozen]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => TrustLedgerStatus::Closed]);
    }
}
