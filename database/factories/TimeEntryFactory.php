<?php

namespace Database\Factories;

use App\Enums\TimeEntryStatus;
use App\Models\Firm;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'user_id' => User::factory(),
            'matter_id' => null,
            'client_id' => null,
            'time_tracking_session_id' => null,
            'seconds' => 3600,
            'is_billable' => true,
            'billing_rate_cents_snapshot' => null,
            'description' => 'Reviewed intake documents',
            'worked_on' => now()->toDateString(),
            'status' => TimeEntryStatus::Draft,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_reason' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function approved(int $rateCents = 25000): static
    {
        return $this->state(fn () => [
            'status' => TimeEntryStatus::Approved,
            'approved_at' => now(),
            'billing_rate_cents_snapshot' => $rateCents,
        ]);
    }

    public function nonBillable(): static
    {
        return $this->state(fn () => ['is_billable' => false]);
    }

    public function seconds(int $seconds): static
    {
        return $this->state(fn () => ['seconds' => $seconds]);
    }
}
