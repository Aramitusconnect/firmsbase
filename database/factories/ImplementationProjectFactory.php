<?php

namespace Database\Factories;

use App\Enums\ImplementationProjectStatus;
use App\Models\Firm;
use App\Models\ImplementationProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImplementationProject>
 */
class ImplementationProjectFactory extends Factory
{
    protected $model = ImplementationProject::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'status' => ImplementationProjectStatus::NotStarted->value,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
