<?php

namespace Database\Factories;

use App\Enums\PaymentRequestAmountRule;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PaymentRequest;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<PaymentRequest>
 */
class PaymentRequestFactory extends Factory
{
    protected $model = PaymentRequest::class;

    /**
     * payment_requests has permanent FORCE ROW LEVEL SECURITY, so every
     * INSERT must run under the row's own app.current_firm_id context —
     * see MatterFactory::create()'s docblock for the full rationale.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);
        $models = $results instanceof Model ? new Collection([$results]) : $results;
        $service = new TenantContextService;

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'client_id' => Client::factory(),
            'purpose' => PaymentRequestPurpose::EarnedFee,
            'amount_rule' => PaymentRequestAmountRule::Fixed,
            'requested_amount_cents' => $this->faker->numberBetween(5000, 500000),
            'currency' => 'usd',
            'status' => PaymentRequestStatus::Draft,
            'created_by_firm_user_id' => FirmUser::factory(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => PaymentRequestStatus::Active,
            'activated_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }
}
