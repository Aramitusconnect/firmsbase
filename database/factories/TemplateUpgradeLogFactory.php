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
        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * Section 39A-3L, Checkpoint 7 — installed_template_pack_id is now
     * derived from the SAME InstalledTemplatePack that firm_id comes
     * from (rather than two independent random factory chains), closing
     * the cross-firm mismatch bug confirmed by Phase A audit: a bare
     * TemplateUpgradeLog::factory()->create() previously resolved
     * firm_id and installed_template_pack_id to two unrelated firms.
     */
    public function definition(): array
    {
        $installedPack = InstalledTemplatePack::factory()->create();

        return [
            'firm_id' => $installedPack->firm_id,
            'installed_template_pack_id' => $installedPack->id,
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
