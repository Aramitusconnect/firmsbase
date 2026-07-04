<?php

namespace Database\Factories;

use App\Enums\InvoiceLineType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'time_entry_id' => null,
            'line_type' => InvoiceLineType::ManualCharge,
            'description' => 'Filing fee',
            'quantity' => 1,
            'rate_cents' => 5000,
            'amount_cents' => 5000,
            'sort_order' => 0,
        ];
    }

    public function forInvoice(Invoice $invoice): static
    {
        return $this->state(fn () => ['invoice_id' => $invoice->id]);
    }
}
