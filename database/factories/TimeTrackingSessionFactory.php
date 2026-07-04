<?php

namespace Database\Factories;

use App\Enums\TimeTrackingSessionStatus;
use App\Models\Firm;
use App\Models\TimeTrackingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeTrackingSession>
 */
class TimeTrackingSessionFactory extends Factory
{
    protected $model = TimeTrackingSession::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'user_id' => User::factory(),
            'matter_id' => null,
            'client_id' => null,
            'status' => TimeTrackingSessionStatus::Active,
            'started_at' => now(),
            'accumulated_seconds' => 0,
            'last_resumed_at' => now(),
            'ended_at' => null,
            'total_seconds' => null,
            'is_billable' => true,
            'description' => 'Drafting motion',
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function stopped(int $totalSeconds): static
    {
        return $this->state(fn () => [
            'status' => TimeTrackingSessionStatus::Stopped,
            'accumulated_seconds' => $totalSeconds,
            'total_seconds' => $totalSeconds,
            'last_resumed_at' => null,
            'ended_at' => now(),
        ]);
    }
}
