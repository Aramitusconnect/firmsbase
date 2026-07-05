<?php

namespace Database\Factories;

use App\Enums\TrustAccountStatus;
use App\Models\Firm;
use App\Models\TrustAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrustAccount>
 */
class TrustAccountFactory extends Factory
{
    protected $model = TrustAccount::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'account_name' => 'Firm IOLTA Trust Account',
            'bank_name_reference' => 'Reference Bank (no real bank integration)',
            'status' => TrustAccountStatus::Active,
            'opened_at' => now(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => TrustAccountStatus::Suspended]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => TrustAccountStatus::Closed]);
    }
}
