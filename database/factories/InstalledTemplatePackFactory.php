<?php

namespace Database\Factories;

use App\Enums\InstalledTemplatePackStatus;
use App\Models\Firm;
use App\Models\InstalledTemplatePack;
use App\Models\TemplatePackVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstalledTemplatePack>
 */
class InstalledTemplatePackFactory extends Factory
{
    protected $model = InstalledTemplatePack::class;

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
