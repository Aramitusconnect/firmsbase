<?php

namespace Database\Factories;

use App\Enums\MatterReadinessStatus;
use App\Models\Matter;
use App\Models\MatterReadinessScore;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<MatterReadinessScore>
 */
class MatterReadinessScoreFactory extends Factory
{
    protected $model = MatterReadinessScore::class;

    /**
     * Section 39A-3L, Checkpoint 14 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A): groups resolved
     * models by firm_id and activates the matching PostgreSQL session
     * context per group before inserting, so a bare
     * MatterReadinessScore::factory()->create() works correctly even
     * called from outside any already-active tenant context.
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

    /**
     * firm_id and matter_id used to be two independent random Factory
     * chains (the same bug class as Checkpoints 5/7/8/10/12/13): a bare
     * MatterReadinessScore::factory()->create() could resolve a matter
     * belonging to a DIFFERENT firm than the one written to firm_id.
     * Fixed here by creating one authoritative Matter up front and
     * deriving both firm_id and matter_id from it — mirrors
     * forMatter()'s already-correct pattern below.
     */
    public function definition(): array
    {
        $matter = Matter::factory()->create();

        return [
            'firm_id' => $matter->firm_id,
            'matter_id' => $matter->id,
            'status' => MatterReadinessStatus::NotReady,
            'satisfied_count' => 0,
            'total_count' => 0,
            'breakdown_json' => [],
            'computed_at' => null,
        ];
    }

    public function forMatter(Matter $matter): static
    {
        return $this->state(fn () => ['firm_id' => $matter->firm_id, 'matter_id' => $matter->id]);
    }
}
