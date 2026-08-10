<?php

namespace Database\Factories;

use App\Enums\DomainEventProcessingStatus;
use App\Enums\DomainEventType;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<DomainEvent>
 */
class DomainEventFactory extends Factory
{
    protected $model = DomainEvent::class;

    /**
     * domain_events has permanent FORCE ROW LEVEL SECURITY — every
     * INSERT must run under the row's own app.current_firm_id context.
     * Context-hold pattern, matching every other FORCE-RLS factory
     * since PendingPaymentAllocationFactory.
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
            'event_type' => DomainEventType::PaymentAllocationPending,
            'subject_type' => null,
            'subject_id' => null,
            'correlation_id' => (string) Str::uuid(),
            'causation_event_id' => null,
            'causation_depth' => 0,
            'payload_json' => [],
            'processing_status' => DomainEventProcessingStatus::Pending,
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
}
