<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentAttemptState;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * FirmsVault Pay Gate A2. Mirrors PaymentAllocationFactory's
 * tenant-context-aware create() verbatim: the target table is FORCE
 * RLS, so a plain factory insert with no app.current_firm_id set would
 * be rejected by the policy. This override sets context per firm_id
 * group before storing, exactly as the established convention requires.
 *
 * @extends Factory<PaymentAttempt>
 */
class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

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

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'state' => PaymentAttemptState::Created,
            'amount_cents' => $this->faker->numberBetween(1000, 500000),
            'currency' => 'USD',
        ];
    }

    public function captured(): static
    {
        return $this->state(fn () => [
            'state' => PaymentAttemptState::Captured,
            'resolved_at' => now(),
        ]);
    }
}
