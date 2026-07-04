<?php

namespace Database\Factories;

use App\Enums\SeatClass;
use App\Enums\SeatPoolStatus;
use App\Models\Organization;
use App\Models\SeatPool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeatPool>
 */
class SeatPoolFactory extends Factory
{
    protected $model = SeatPool::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'seat_class' => SeatClass::Attorney,
            'total_seats' => 10,
            'allocated_seats' => 0,
            'counting_mode' => 'named',
            'period' => null,
            'status' => SeatPoolStatus::Active,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn () => ['organization_id' => $organization->id]);
    }

    public function seatClass(SeatClass $seatClass): static
    {
        return $this->state(fn () => ['seat_class' => $seatClass]);
    }

    public function totalSeats(int $total): static
    {
        return $this->state(fn () => ['total_seats' => $total]);
    }
}
