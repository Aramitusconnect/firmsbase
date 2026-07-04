<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\FirmPracticeArea;
use App\Models\PracticeArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FirmPracticeArea>
 */
class FirmPracticeAreaFactory extends Factory
{
    protected $model = FirmPracticeArea::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'practice_area_id' => PracticeArea::factory(),
            'is_enabled' => true,
            'enabled_at' => now(),
            'disabled_at' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function forPracticeArea(PracticeArea $practiceArea): static
    {
        return $this->state(fn () => ['practice_area_id' => $practiceArea->id]);
    }
}
