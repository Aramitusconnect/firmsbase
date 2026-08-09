<?php

namespace Database\Factories;

use App\Enums\PaymentReversalType;
use App\Models\Firm;
use App\Models\PaymentReversal;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<PaymentReversal>
 */
class PaymentReversalFactory extends Factory
{
    protected $model = PaymentReversal::class;

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
            'reversal_type' => PaymentReversalType::Refund,
            'amount_cents' => $this->faker->numberBetween(1000, 50000),
            'reason' => $this->faker->sentence(),
            'created_at' => now(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
