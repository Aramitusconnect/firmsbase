<?php

namespace Database\Factories;

use App\Enums\AccountingPeriodEventType;
use App\Models\AccountingPeriod;
use App\Models\AccountingPeriodEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<AccountingPeriodEvent>
 */
class AccountingPeriodEventFactory extends Factory
{
    protected $model = AccountingPeriodEvent::class;

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
            'accounting_period_id' => AccountingPeriod::factory(),
            'event_type' => AccountingPeriodEventType::Closed,
            'actor_firm_user_id' => FirmUser::factory(),
            'reason' => null,
            'created_at' => now(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
