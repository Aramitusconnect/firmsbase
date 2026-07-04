<?php

namespace Database\Factories;

use App\Enums\TemplateUpgradeLogStatus;
use App\Models\Firm;
use App\Models\InstalledTemplatePack;
use App\Models\TemplatePackVersion;
use App\Models\TemplateUpgradeLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemplateUpgradeLog>
 */
class TemplateUpgradeLogFactory extends Factory
{
    protected $model = TemplateUpgradeLog::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'installed_template_pack_id' => InstalledTemplatePack::factory(),
            'from_version_id' => TemplatePackVersion::factory(),
            'to_version_id' => TemplatePackVersion::factory(),
            'status' => TemplateUpgradeLogStatus::Applied,
            'applied_at' => now(),
            'applied_by' => null,
            'rollback_of_id' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function rolledBack(): static
    {
        return $this->state(fn () => ['status' => TemplateUpgradeLogStatus::RolledBack]);
    }
}
