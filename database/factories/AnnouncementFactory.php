<?php

namespace Database\Factories;

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Enums\AnnouncementType;
use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        return [
            'type' => AnnouncementType::General->value,
            'severity' => AnnouncementSeverity::Info->value,
            'status' => AnnouncementStatus::Draft->value,
            'title' => $this->faker->sentence(5),
            'body' => $this->faker->paragraph(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => AnnouncementStatus::Published->value]);
    }

    public function severity(AnnouncementSeverity $severity): static
    {
        return $this->state(fn () => ['severity' => $severity->value]);
    }

    public function minSeverity(AnnouncementSeverity $minSeverity): static
    {
        return $this->state(fn () => ['min_severity' => $minSeverity->value]);
    }
}
