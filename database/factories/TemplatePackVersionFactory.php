<?php

namespace Database\Factories;

use App\Enums\TemplatePackStatus;
use App\Models\TemplatePack;
use App\Models\TemplatePackVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemplatePackVersion>
 */
class TemplatePackVersionFactory extends Factory
{
    protected $model = TemplatePackVersion::class;

    public function definition(): array
    {
        return [
            'template_pack_id' => TemplatePack::factory(),
            'version' => '1.0.0',
            'status' => TemplatePackStatus::Published,
            'release_notes' => $this->faker->sentence(),
            'published_at' => now(),
        ];
    }

    public function forPack(TemplatePack $pack): static
    {
        return $this->state(fn () => ['template_pack_id' => $pack->id]);
    }

    public function version(string $version): static
    {
        return $this->state(fn () => ['version' => $version]);
    }
}
