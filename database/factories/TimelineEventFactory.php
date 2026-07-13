<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\TimelineEvent;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<TimelineEvent>
 */
class TimelineEventFactory extends Factory
{
    protected $model = TimelineEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'subject_type' => null,
            'subject_id' => null,
            'event_type' => 'lead_created',
            'actor_type' => null,
            'actor_id' => null,
            'occurred_at' => now(),
            'metadata_json' => [],
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function eventType(string $type): static
    {
        return $this->state(fn () => ['event_type' => $type]);
    }

    public function forSubject(\Illuminate\Database\Eloquent\Model $subject): static
    {
        return $this->state(fn () => [
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
        ]);
    }

    /**
     * Section 39A-3L Phase B6 — same context-hold create() fix as the
     * six prior nullable-firm_id factories. definition() always
     * resolves a real Firm::factory() (never null, matching
     * TimelineEventRecorder::record()'s own non-nullable Firm
     * contract), so the null-firm_id branch below is not currently
     * reachable through this factory — a purely forward-looking,
     * symmetric fix, not a response to any existing regression.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = app(TenantContextService::class);

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $firmId = $group->first()->firm_id;

            if ($firmId === null) {
                $service->clearDatabaseTenantContext();
                $this->store($group);

                return;
            }

            $service->setDatabaseTenantContextForFirmId($firmId);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }
}
