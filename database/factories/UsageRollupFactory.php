<?php

namespace Database\Factories;

use App\Enums\UsageRollupMetric;
use App\Models\BillingAccount;
use App\Models\UsageRollup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageRollup>
 */
class UsageRollupFactory extends Factory
{
    protected $model = UsageRollup::class;

    public function definition(): array
    {
        return [
            'billing_account_id' => BillingAccount::factory(),
            'firm_id' => null,
            'metric' => UsageRollupMetric::AiTokens,
            'period_starts_at' => now()->startOfMonth(),
            'period_ends_at' => now()->endOfMonth(),
            'quantity' => 1000,
            'unit' => 'tokens',
        ];
    }

    public function forBillingAccount(BillingAccount $billingAccount): static
    {
        return $this->state(fn () => ['billing_account_id' => $billingAccount->id]);
    }

    public function metric(UsageRollupMetric $metric): static
    {
        return $this->state(fn () => ['metric' => $metric]);
    }
}
