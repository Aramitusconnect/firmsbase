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
        $service = new TenantContextService;

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * firm_id and matter_id are derived from ONE authoritative Matter —
     * mirrors forMatter()'s pattern below.
     *
     * Audit fix (eager-factory-side-effects audit): this used to call
     * Matter::factory()->create() as a plain PHP statement at the top
     * of definition() — a real, committed Matter every single time,
     * even when forMatter() below immediately overrides both keys with
     * a caller-supplied matter. Fixed by memoizing the matter behind
     * lazy closures so nothing is created unless it survives,
     * unoverridden, to the final row.
     */
    private ?Matter $lazyMatter = null;

    public function definition(): array
    {
        $this->lazyMatter = null;

        return [
            'firm_id' => function () {
                $this->lazyMatter ??= Matter::factory()->create();

                return $this->lazyMatter->firm_id;
            },
            'matter_id' => function () {
                $this->lazyMatter ??= Matter::factory()->create();

                return $this->lazyMatter->id;
            },
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
