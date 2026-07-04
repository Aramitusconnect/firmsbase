<?php

namespace Database\Factories;

use App\Models\FirmLicense;
use App\Models\LicenseEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LicenseEvent>
 */
class LicenseEventFactory extends Factory
{
    protected $model = LicenseEvent::class;

    public function definition(): array
    {
        return [
            'licensable_type' => FirmLicense::class,
            'licensable_id' => FirmLicense::factory(),
            'event_type' => 'status_changed',
            'from_status' => 'trial',
            'to_status' => 'active',
            'reason' => null,
            'actor_type' => 'System',
            'actor_id' => null,
            'metadata' => [],
        ];
    }

    public function forLicensable(string $type, int $id): static
    {
        return $this->state(fn () => ['licensable_type' => $type, 'licensable_id' => $id]);
    }

    public function eventType(string $eventType): static
    {
        return $this->state(fn () => ['event_type' => $eventType]);
    }
}
