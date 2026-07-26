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
     * The event and its payment are always tied to the SAME firm.
     *
     * Audit fix (eager-factory-side-effects audit): this used to call
     * Payment::factory()->create() as a plain PHP statement at the top
     * of definition() — a real, committed Payment (+ its own nested
     * Firm/Client) every single time, even when forPayment() below
     * immediately overrides both keys with a caller-supplied payment.
     * Fixed by memoizing the payment behind lazy closures so nothing is
     * created unless it survives, unoverridden, to the final row.
     */
    private ?Payment $lazyPayment = null;

    public function definition(): array
    {
        $this->lazyPayment = null;

        return [
            'firm_id' => function () {
                $this->lazyPayment ??= Payment::factory()->create();

                return $this->lazyPayment->firm_id;
            },
            'payment_id' => function () {
                $this->lazyPayment ??= Payment::factory()->create();

                return $this->lazyPayment->id;
            },
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
