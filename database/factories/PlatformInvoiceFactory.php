<?php

namespace Database\Factories;

use App\Enums\PlatformInvoiceStatus;
use App\Models\BillingAccount;
use App\Models\PlatformInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformInvoice>
 */
class PlatformInvoiceFactory extends Factory
{
    protected $model = PlatformInvoice::class;

    public function definition(): array
    {
        return [
            'billing_account_id' => BillingAccount::factory(),
            'platform_subscription_id' => null,
            'status' => PlatformInvoiceStatus::Draft,
            'period_starts_at' => now()->startOfMonth(),
            'period_ends_at' => now()->endOfMonth(),
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'due_at' => now()->addDays(15),
            'paid_at' => null,
            'voided_at' => null,
        ];
    }

    public function forBillingAccount(BillingAccount $billingAccount): static
    {
        return $this->state(fn () => ['billing_account_id' => $billingAccount->id]);
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => PlatformInvoiceStatus::Open]);
    }

    public function totals(int $subtotalCents, int $taxCents = 0): static
    {
        return $this->state(fn () => [
            'subtotal_cents' => $subtotalCents,
            'tax_cents' => $taxCents,
            'total_cents' => $subtotalCents + $taxCents,
        ]);
    }
}
