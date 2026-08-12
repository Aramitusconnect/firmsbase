<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MarketplaceIntakeStatus;
use App\Marketplace\Models\MarketplaceIntake;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<MarketplaceIntake>
 */
class MarketplaceIntakeFactory extends Factory
{
    protected $model = MarketplaceIntake::class;

    /**
     * marketplace_intakes has permanent FORCE ROW LEVEL SECURITY, so
     * every INSERT must run under the row's own app.current_firm_id
     * context — see PaymentRequestFactory's docblock for the full
     * rationale, reused verbatim here.
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
            'directory_firm_id' => null,
            'practice_area_id' => null,
            'status' => MarketplaceIntakeStatus::Started,
            'prospect_name' => $this->faker->name(),
            'prospect_email' => $this->faker->safeEmail(),
            'prospect_phone' => $this->faker->phoneNumber(),
            'structured_data' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function status(MarketplaceIntakeStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => MarketplaceIntakeStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }
}
