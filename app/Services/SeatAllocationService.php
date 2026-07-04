<?php

namespace App\Services;

use App\Enums\SeatAllocationStatus;
use App\Enums\SeatClass;
use App\Models\Firm;
use App\Models\SeatAllocation;
use App\Models\SeatPool;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * SeatAllocationService — the only place seat_allocations rows are
 * created or revoked. allocateFromPool() enforces "never silently
 * exceed the pool" (PDF edge case: "Organization pool is exhausted
 * while a member firm invites a user" -> block with a clear
 * pool-exhausted message) by checking SeatPool::remainingSeats() inside
 * the same transaction that increments seat_pools.allocated_seats.
 */
class SeatAllocationService
{
    /**
     * Direct, non-pooled seat grant from the firm's own license/plan.
     */
    public function allocateDirect(Firm $firm, SeatClass $seatClass, int $seats, ?User $actor = null): SeatAllocation
    {
        return SeatAllocation::create([
            'firm_id' => $firm->id,
            'seat_pool_id' => null,
            'seat_class' => $seatClass,
            'seats_allocated' => $seats,
            'status' => SeatAllocationStatus::Active,
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * @throws \RuntimeException if the pool does not have $seats remaining.
     */
    public function allocateFromPool(Firm $firm, SeatPool $pool, int $seats, ?User $actor = null): SeatAllocation
    {
        return DB::transaction(function () use ($firm, $pool, $seats, $actor) {
            $lockedPool = SeatPool::query()->whereKey($pool->id)->lockForUpdate()->firstOrFail();

            if ($lockedPool->remainingSeats() < $seats) {
                throw new \RuntimeException(
                    "Seat pool #{$lockedPool->id} ({$lockedPool->seat_class->value}) does not have {$seats} seat(s) remaining."
                );
            }

            $lockedPool->update(['allocated_seats' => $lockedPool->allocated_seats + $seats]);

            return SeatAllocation::create([
                'firm_id' => $firm->id,
                'seat_pool_id' => $lockedPool->id,
                'seat_class' => $lockedPool->seat_class,
                'seats_allocated' => $seats,
                'status' => SeatAllocationStatus::Active,
                'created_by' => $actor?->id,
            ]);
        });
    }

    public function revoke(SeatAllocation $allocation): SeatAllocation
    {
        return DB::transaction(function () use ($allocation) {
            if ($allocation->isPooled() && $allocation->status === SeatAllocationStatus::Active) {
                $pool = SeatPool::query()->whereKey($allocation->seat_pool_id)->lockForUpdate()->first();

                if ($pool) {
                    $pool->update(['allocated_seats' => max(0, $pool->allocated_seats - $allocation->seats_allocated)]);
                }
            }

            $allocation->update(['status' => SeatAllocationStatus::Revoked]);

            return $allocation->fresh();
        });
    }
}
