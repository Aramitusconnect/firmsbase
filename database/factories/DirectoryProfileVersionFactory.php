<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryProfileVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectoryProfileVersion>
 */
class DirectoryProfileVersionFactory extends Factory
{
    protected $model = DirectoryProfileVersion::class;

    public function definition(): array
    {
        return [
            'directory_firm_id' => DirectoryFirm::factory(),
            'changed_fields' => ['phone' => ['old' => '5555550100', 'new' => '5555550101']],
            'actor_type' => 'platform_admin',
            'actor_id' => null,
            'source' => DataProvenanceSourceType::AdminEntered,
            'publication_state' => DirectoryPublicationState::Published,
        ];
    }

    public function forFirm(DirectoryFirm $firm): static
    {
        return $this->state(fn () => ['directory_firm_id' => $firm->id]);
    }
}
