<?php

namespace Database\Factories;

use App\Enums\AutomationActionType;
use App\Enums\DomainEventType;
use App\Models\AutomationRule;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<AutomationRule>
 */
class AutomationRuleFactory extends Factory
{
    protected $model = AutomationRule::class;

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
            'name' => $this->faker->sentence(3),
            'description' => null,
            'event_type' => DomainEventType::PaymentAllocationPending,
            'enabled' => true,
            'priority' => 0,
            'conditions_json' => [],
            'actions_json' => [
                ['action_type' => AutomationActionType::CreateTask->value, 'config' => ['title' => 'Automated task']],
            ],
            'requires_approval' => false,
            'is_starter_template' => false,
            'version' => 1,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function ofType(DomainEventType $type): static
    {
        return $this->state(fn () => ['event_type' => $type]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
