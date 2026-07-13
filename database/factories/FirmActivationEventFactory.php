<?php

namespace Database\Factories;

use App\Enums\FirmActivationEventStatus;
use App\Models\Firm;
use App\Models\FirmActivationEvent;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<FirmActivationEvent>
 */
class FirmActivationEventFactory extends Factory
{
    protected $model = FirmActivationEvent::class;

    /**
     * Section 39A-3L, Checkpoint 3 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A, e.g.
     * ActivationChecklistFactory from Checkpoint 2): groups resolved
     * models by firm_id and activates the matching PostgreSQL session
     * context per group before inserting, so a bare
     * FirmActivationEvent::factory()->create() works correctly even
     * called from outside any already-active tenant context.
     * Deliberately does not clear context afterward. definition()'s
     * only tenant FK is firm_id itself (via Firm::factory()) — no
     * cross-firm mismatch risk to fix here. Added as future-proofing
     * ahead of FORCE landing even though no test currently exercises
     * the bare factory.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);
        $models = $results instanceof Model ? new Collection([$results]) : $results;
        $service = new TenantContextService();

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
            'event_type' => 'checklist_item_completed',
            'status' => FirmActivationEventStatus::Completed,
            'checklist_item_key' => null,
            'blocking_reason' => null,
            'actor_user_id' => null,
            'metadata_json' => [],
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function blocked(string $reason): static
    {
        return $this->state(fn () => ['status' => FirmActivationEventStatus::Blocked, 'blocking_reason' => $reason]);
    }
}
