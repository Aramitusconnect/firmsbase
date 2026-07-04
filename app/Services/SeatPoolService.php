<?php

namespace App\Services;

use App\Enums\SeatClass;
use App\Enums\SeatPoolStatus;
use App\Models\Organization;
use App\Models\SeatPool;

/**
 * SeatPoolService — the only place seat_pools rows are created or
 * resized. allocated_seats is maintained as a running counter by
 * SeatAllocationService (increment on allocateFromPool(), decrement on
 * revoke() of a pooled allocation) — never recomputed by summing
 * seat_allocations here, so pool-exhaustion checks stay O(1).
 */
class SeatPoolService
{
    public function createPool(
        Organization $organization,
        SeatClass $seatClass,
        int $totalSeats,
        string $countingMode = 'named',
        ?string $period = null,
    ): SeatPool {
        return SeatPool::create([
            'organization_id' => $organization->id,
            'seat_class' => $seatClass,
            'total_seats' => $totalSeats,
            'allocated_seats' => 0,
            'counting_mode' => $countingMode,
            'period' => $period,
            'status' => SeatPoolStatus::Active,
        ]);
    }

    public function resize(SeatPool $pool, int $newTotalSeats): SeatPool
    {
        return tap($pool)->update(['total_seats' => $newTotalSeats])->fresh();
    }

    public function suspend(SeatPool $pool): SeatPool
    {
        return tap($pool)->update(['status' => SeatPoolStatus::Suspended])->fresh();
    }

    public function close(SeatPool $pool): SeatPool
    {
        return tap($pool)->update(['status' => SeatPoolStatus::Closed])->fresh();
    }
}
