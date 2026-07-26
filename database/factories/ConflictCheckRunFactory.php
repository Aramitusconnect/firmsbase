<?php

namespace Database\Factories;

use App\Enums\ConflictCheckRunStatus;
use App\Enums\ConflictCheckScope;
use App\Models\ConflictCheckRun;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<ConflictCheckRun>
 */
class ConflictCheckRunFactory extends Factory
{
    protected $model = ConflictCheckRun::class;

    /**
     * Context-hold pattern (Section 39A-3I, matching every prior
     * FORCE-RLS factory since 39A-3A): groups resolved models by
     * firm_id and activates the matching PostgreSQL session context
     * per group before inserting, so a bare
     * ConflictCheckRun::factory()->create() works correctly even
     * called from outside any already-active tenant context.
     * Deliberately does not clear context afterward.
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
     * firm_id derives from the SAME matter as matter_id.
     *
     * Audit fix (eager-factory-side-effects audit): this used to call
     * Matter::factory()->create() as a plain PHP statement at the top
     * of definition() — a real, committed Matter (+ its own nested
     * Firm) every single time, even when forMatter()/forFirm() below
     * immediately override both keys with a caller-supplied matter.
     * Fixed by memoizing the matter behind lazy closures so nothing is
     * created unless it survives, unoverridden, to the final row.
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
            'requested_by' => null,
            'status' => ConflictCheckRunStatus::Pending,
            'scope' => ConflictCheckScope::Firm,
            'searched_terms_json' => [],
            'result_count' => 0,
            'completed_at' => null,
        ];
    }

    public function forMatter(Matter $matter): static
    {
        return $this->state(fn () => ['firm_id' => $matter->firm_id, 'matter_id' => $matter->id]);
    }

    /**
     * Ties both the run AND its nested matter to the given firm —
     * mirrors PaymentFactory::forFirm()/InvoiceFactory::forFirm() so a
     * caller with a specific pre-existing firm never has to assemble a
     * consistent firm/matter pair by hand.
     */
    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'matter_id' => Matter::factory()->forFirm($firm),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ConflictCheckRunStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
