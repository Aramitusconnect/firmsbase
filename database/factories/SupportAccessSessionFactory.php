<?php

namespace Database\Factories;

use App\Enums\SupportAccessSessionStatus;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportAccessSession>
 */
class SupportAccessSessionFactory extends Factory
{
    protected $model = SupportAccessSession::class;

    public function definition(): array
    {
        return [
            'support_access_request_id' => SupportAccessRequest::factory(),
            'firm_id' => Firm::factory(),
            'platform_admin_id' => PlatformAdmin::factory(),
            'status' => SupportAccessSessionStatus::Active->value,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => SupportAccessSessionStatus::Expired->value,
            'started_at' => now()->subHours(3),
            'expires_at' => now()->subHour(),
        ]);
    }
}
