<?php

namespace Database\Factories;

use App\Enums\MigrationProjectStatus;
use App\Enums\MigrationSourceType;
use App\Models\Firm;
use App\Models\MigrationProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MigrationProject>
 */
class MigrationProjectFactory extends Factory
{
    protected $model = MigrationProject::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'source_type' => MigrationSourceType::Spreadsheets->value,
            'status' => MigrationProjectStatus::Draft->value,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function sourceType(MigrationSourceType $type): static
    {
        return $this->state(fn () => ['source_type' => $type->value]);
    }
}
