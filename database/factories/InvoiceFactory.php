<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'client_id' => Client::factory(),
            'matter_id' => null,
            'invoice_type' => InvoiceType::TimeAndExpense,
            'status' => InvoiceStatus::Draft,
            'currency' => 'usd',
            'subtotal_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'created_by' => null,
        ];
    }

    /**
     * Ties both the invoice AND its nested client to the given firm —
     * same reasoning as MatterFactory::forFirm() in Phase 2.
     */
    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'client_id' => Client::factory()->forFirm($firm),
        ]);
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => [
            'firm_id' => $client->firm_id,
            'client_id' => $client->id,
        ]);
    }

    public function status(InvoiceStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function totals(int $subtotalCents): static
    {
        return $this->state(fn () => ['subtotal_cents' => $subtotalCents, 'total_cents' => $subtotalCents]);
    }
}
