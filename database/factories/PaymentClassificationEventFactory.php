<?php

namespace Database\Factories;

use App\Enums\PaymentClassification;
use App\Models\Payment;
use App\Models\PaymentClassificationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentClassificationEvent>
 */
class PaymentClassificationEventFactory extends Factory
{
    protected $model = PaymentClassificationEvent::class;

    /**
     * The event and its payment are always tied to the SAME firm —
     * generating one payment here up front (rather than letting
     * firm_id and payment_id resolve as two independent
     * Firm::factory()/Payment::factory() calls) is deliberate: a bare
     * PaymentClassificationEvent::factory()->create() with no state
     * must never produce an event whose payment belongs to an
     * unrelated firm, matching the root-cause fix already applied to
     * PaymentFactory in Section 39A-3H.
     */
    public function definition(): array
    {
        $payment = Payment::factory()->create();

        return [
            'firm_id' => $payment->firm_id,
            'payment_id' => $payment->id,
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
