<?php

namespace Database\Factories;

use App\Enums\TrustLedgerEntryType;
use App\Models\Firm;
use App\Models\TrustLedger;
use App\Models\TrustLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrustLedgerEntry>
 */
class TrustLedgerEntryFactory extends Factory
{
    protected $model = TrustLedgerEntry::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'trust_ledger_id' => TrustLedger::factory(),
            'matter_id' => null,
            'entry_type' => TrustLedgerEntryType::Deposit,
            'amount_cents' => 10000,
            'posted_at' => now(),
        ];
    }

    public function deposit(int $amountCents = 10000): static
    {
        return $this->state(fn () => [
            'entry_type' => TrustLedgerEntryType::Deposit,
            'amount_cents' => $amountCents,
        ]);
    }

    public function withdrawalToInvoice(int $amountCents = -5000): static
    {
        return $this->state(fn () => [
            'entry_type' => TrustLedgerEntryType::WithdrawalToInvoice,
            'amount_cents' => $amountCents,
        ]);
    }
}
