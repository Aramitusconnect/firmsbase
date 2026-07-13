<?php

namespace Database\Factories;

use App\Enums\ActivationChecklistStatus;
use App\Models\ActivationChecklist;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<ActivationChecklist>
 */
class ActivationChecklistFactory extends Factory
{
    protected $model = ActivationChecklist::class;

    /**
     * Section 39A-3L, Checkpoint 2 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A): groups resolved
     * models by firm_id and activates the matching PostgreSQL session
     * context per group before inserting, so a bare
     * ActivationChecklist::factory()->create() works correctly even
     * called from outside any already-active tenant context.
     * Deliberately does not clear context afterward. definition()'s
     * only tenant FK is firm_id itself (via Firm::factory()) — no
     * cross-firm mismatch risk to fix here.
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
            'status' => ActivationChecklistStatus::InProgress,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ActivationChecklistStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
