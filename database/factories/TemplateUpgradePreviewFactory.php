<?php

namespace Database\Factories;

use App\Enums\TemplateUpgradePreviewStatus;
use App\Models\Firm;
use App\Models\InstalledTemplatePack;
use App\Models\TemplatePackVersion;
use App\Models\TemplateUpgradePreview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemplateUpgradePreview>
 */
class TemplateUpgradePreviewFactory extends Factory
{
    protected $model = TemplateUpgradePreview::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'installed_template_pack_id' => InstalledTemplatePack::factory(),
            'from_version_id' => TemplatePackVersion::factory(),
            'to_version_id' => TemplatePackVersion::factory(),
            'status' => TemplateUpgradePreviewStatus::Generated,
            'diff_summary_json' => ['from_version' => '1.0.0', 'to_version' => '2.0.0'],
            'previewed_at' => now(),
            'previewed_by' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function forInstalledPack(InstalledTemplatePack $installed): static
    {
        return $this->state(fn () => [
            'firm_id' => $installed->firm_id,
            'installed_template_pack_id' => $installed->id,
            'from_version_id' => $installed->template_pack_version_id,
        ]);
    }
}
