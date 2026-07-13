<?php

namespace Database\Factories;

use App\Enums\InstalledTemplatePackStatus;
use App\Models\Firm;
use App\Models\InstalledTemplatePack;
use App\Models\TemplatePackVersion;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<InstalledTemplatePack>
 */
class InstalledTemplatePackFactory extends Factory
{
    protected $model = InstalledTemplatePack::class;

    /**
     * Section 39A-3L, Checkpoint 6 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A, e.g.
     * FirmEntitlementFactory from Checkpoint 4): groups resolved models
     * by firm_id and activates the matching PostgreSQL session context
     * per group before inserting, so a bare
     * InstalledTemplatePack::factory()->create() (and the
     * ->forFirm($firm)->create() form used by
     * TemplateUpgradeLogFactory/TemplateUpgradePreviewFactory's tests)
     * works correctly even called from outside any already-active
     * tenant context. definition()'s only tenant FK is firm_id itself
     * (via Firm::factory()) — template_pack_id/template_pack_version_id
     * both derive from one shared TemplatePackVersion::factory()
     * call, and template_packs/template_pack_versions are confirmed
     * genuinely global/exempt catalog tables (no firm_id column), so
     * there is no cross-firm mismatch risk to repair here.
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
        $version = TemplatePackVersion::factory()->create();

        return [
            'firm_id' => Firm::factory(),
            'template_pack_id' => $version->template_pack_id,
            'template_pack_version_id' => $version->id,
            'status' => InstalledTemplatePackStatus::Active,
            'installed_at' => now(),
            'disabled_at' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function forVersion(TemplatePackVersion $version): static
    {
        return $this->state(fn () => [
            'template_pack_id' => $version->template_pack_id,
            'template_pack_version_id' => $version->id,
        ]);
    }
}
