<?php

namespace Database\Factories;

use App\Enums\SeatAllocationStatus;
use App\Enums\SeatClass;
use App\Models\Firm;
use App\Models\SeatAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeatAllocation>
 */
class SeatAllocationFactory extends Factory
{
    protected $model = SeatAllocation::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'seat_pool_id' => null,
            'seat_class' => SeatClass::Attorney,
            'seats_allocated' => 5,
            'status' => SeatAllocationStatus::Active,
            'created_by' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function seatClass(SeatClass $seatClass): static
    {
        return $this->state(fn () => ['seat_class' => $seatClass]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => SeatAllocationStatus::Revoked]);
    }
}
