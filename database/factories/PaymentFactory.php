<?php

namespace Database\Factories;

use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'client_id' => Client::factory(),
            'matter_id' => null,
            'invoice_id' => null,
            'payment_plan_installment_id' => null,
            'amount_cents' => 10000,
            'currency' => 'usd',
            'payment_method' => ManualPaymentMethod::Check,
            'payment_classification' => PaymentClassification::OperatingPayment,
            'status' => PaymentStatus::Succeeded,
            'external_reference' => null,
            'idempotency_key' => null,
            'rejection_reason' => null,
            'recorded_by' => null,
        ];
    }

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

    public function blocked(string $reason = 'Explicitly classified as blocked.'): static
    {
        return $this->state(fn () => [
            'payment_classification' => PaymentClassification::BlockedPayment,
            'status' => PaymentStatus::Blocked,
            'rejection_reason' => $reason,
        ]);
    }

    public function idempotencyKey(string $key): static
    {
        return $this->state(fn () => ['idempotency_key' => $key]);
    }
}
