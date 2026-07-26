<?php

namespace Database\Factories;

use App\Enums\TemplateUpgradeLogStatus;
use App\Models\Firm;
use App\Models\InstalledTemplatePack;
use App\Models\TemplatePackVersion;
use App\Models\TemplateUpgradeLog;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<TemplateUpgradeLog>
 */
class TemplateUpgradeLogFactory extends Factory
{
    protected $model = TemplateUpgradeLog::class;

    /**
     * Section 39A-3L, Checkpoint 7 — context-hold pattern (matching
     * InstalledTemplatePackFactory from Checkpoint 6 and every prior
     * FORCE-RLS factory since 39A-3A): groups resolved models by
     * firm_id and activates the matching PostgreSQL session context per
     * group before inserting, so a bare
     * TemplateUpgradeLog::factory()->create() (and the
     * ->forFirm($firm)->create() form used by
     * TemplateUpgradeLogServiceTest) works correctly even called from
     * outside any already-active tenant context.
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
     * installed_template_pack_id is derived from the SAME
     * InstalledTemplatePack that firm_id comes from.
     *
     * Audit fix (eager-factory-side-effects audit): this used to call
     * InstalledTemplatePack::factory()->create() as a plain PHP
     * statement at the top of definition() — a real, committed
     * InstalledTemplatePack every single time, even when forFirm()
     * below immediately overrides both keys with a caller-supplied
     * firm. Fixed by memoizing the pack behind lazy closures so nothing
     * is created unless it survives, unoverridden, to the final row.
     */
    private ?InstalledTemplatePack $lazyInstalledPack = null;

    public function definition(): array
    {
        $this->lazyInstalledPack = null;

        return [
            'firm_id' => function () {
                $this->lazyInstalledPack ??= InstalledTemplatePack::factory()->create();

                return $this->lazyInstalledPack->firm_id;
            },
            'installed_template_pack_id' => function () {
                $this->lazyInstalledPack ??= InstalledTemplatePack::factory()->create();

                return $this->lazyInstalledPack->id;
            },
            'from_version_id' => TemplatePackVersion::factory(),
            'to_version_id' => TemplatePackVersion::factory(),
            'status' => TemplateUpgradeLogStatus::Applied,
            'applied_at' => now(),
            'applied_by' => null,
            'rollback_of_id' => null,
        ];
    }

    /**
     * Section 39A-3L, Checkpoint 7 — re-derives installed_template_pack_id
     * from a NEW InstalledTemplatePack created for the given firm,
     * rather than only overriding the bare firm_id column, so the
     * cross-firm mismatch cannot persist even via forFirm().
     */
    public function forFirm(Firm $firm): static
    {
        return $this->state(function () use ($firm) {
            $installedPack = InstalledTemplatePack::factory()->forFirm($firm)->create();

            return [
                'firm_id' => $firm->id,
                'installed_template_pack_id' => $installedPack->id,
            ];
        });
    }

    public function rolledBack(): static
    {
        return $this->state(fn () => ['status' => TemplateUpgradeLogStatus::RolledBack]);
    }
}
