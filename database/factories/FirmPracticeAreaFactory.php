<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\FirmPracticeArea;
use App\Models\PracticeArea;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<FirmPracticeArea>
 */
class FirmPracticeAreaFactory extends Factory
{
    protected $model = FirmPracticeArea::class;

    /**
     * Section 39A-3K — context-hold pattern (matching every prior
     * FORCE-RLS factory since 39A-3A): groups resolved models by
     * firm_id and activates the matching PostgreSQL session context per
     * group before inserting, so a bare FirmPracticeArea::factory()
     * ->create() works correctly even called from outside any already-
     * active tenant context. Deliberately does not clear context
     * afterward. practice_area_id has no ownership-consistency risk to
     * fix here — practice_areas is a global, non-tenant-owned catalog
     * table (exempt from RLS entirely), so there is no nested
     * tenant-owned record whose firm could ever mismatch firm_id.
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
            'practice_area_id' => PracticeArea::factory(),
            'is_enabled' => true,
            'enabled_at' => now(),
            'disabled_at' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function forPracticeArea(PracticeArea $practiceArea): static
    {
        return $this->state(fn () => ['practice_area_id' => $practiceArea->id]);
    }
}
