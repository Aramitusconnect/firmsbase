<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProviderCommandStatus;
use App\Enums\ProviderCommandType;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Models\ProviderCommand;
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
 * @extends Factory<ProviderCommand>
 */
class ProviderCommandFactory extends Factory
{
    protected $model = ProviderCommand::class;

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
        $payload = ['amount_cents' => $this->faker->numberBetween(1000, 50000), 'currency' => 'USD'];

        return [
            'firm_id' => Firm::factory(),
            'command_type' => ProviderCommandType::CapturePayment,
            'aggregate_type' => PaymentAttempt::class,
            'aggregate_id' => $this->faker->numberBetween(1, 100000),
            'idempotency_key' => 'capture:test:'.$this->faker->unique()->uuid(),
            'canonical_payload' => $payload,
            'canonical_payload_hash' => ProviderCommand::canonicalPayloadHash($payload),
            'correlation_id' => $this->faker->uuid(),
            'status' => ProviderCommandStatus::Pending,
        ];
    }
}
