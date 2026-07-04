<?php

namespace Database\Factories;

use App\Models\PlatformInvoice;
use App\Models\PlatformInvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformInvoiceLine>
 */
class PlatformInvoiceLineFactory extends Factory
{
    protected $model = PlatformInvoiceLine::class;

    public function definition(): array
    {
        return [
            'platform_invoice_id' => PlatformInvoice::factory(),
            'firm_id' => null,
            'description' => 'Base plan',
            'quantity' => 1,
            'unit_amount_cents' => 19900,
            'amount_cents' => 19900,
            'usage_metric' => null,
        ];
    }

    public function forInvoice(PlatformInvoice $invoice): static
    {
        return $this->state(fn () => ['platform_invoice_id' => $invoice->id]);
    }
}
