<?php

namespace Database\Factories;

use App\Models\ManualPaymentRecord;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManualPaymentRecord>
 */
class ManualPaymentRecordFactory extends Factory
{
    protected $model = ManualPaymentRecord::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'received_by' => null,
            'received_at' => now(),
            'method_reference' => null,
            'notes' => null,
        ];
    }

    public function forPayment(Payment $payment): static
    {
        return $this->state(fn () => ['payment_id' => $payment->id]);
    }
}
