<?php

namespace Database\Factories;

use App\Enums\PaymentClassification;
use App\Models\Firm;
use App\Models\Payment;
use App\Models\PaymentClassificationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentClassificationEvent>
 */
class PaymentClassificationEventFactory extends Factory
{
    protected $model = PaymentClassificationEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'payment_id' => Payment::factory(),
            'event_type' => 'classification_accepted',
            'requested_classification' => PaymentClassification::OperatingPayment,
            'resolved_classification' => PaymentClassification::OperatingPayment,
            'reason' => null,
            'actor_user_id' => null,
        ];
    }

    public function forPayment(Payment $payment): static
    {
        return $this->state(fn () => ['firm_id' => $payment->firm_id, 'payment_id' => $payment->id]);
    }
}
