<?php

namespace App\Services;

use App\Enums\FirmUserStatus;
use App\Enums\SeatAllocationStatus;
use App\Enums\SeatClass;
use App\Models\Firm;
use App\ValueObjects\SeatUsageSnapshot;

/**
 * SeatEnforcementService — computes allocated-vs-used seats per class
 * for a firm, and answers whether a new invite of a given class may
 * proceed. "used" is ALWAYS computed from firm_users via
 * FirmUser::effectiveSeatClass() — client portal users can never appear
 * here because they are Client rows, never FirmUser rows, so they are
 * uncounted with no special-case code required (project rule 5).
 */
class SeatEnforcementService
{
    public function usageFor(Firm $firm, SeatClass $seatClass): SeatUsageSnapshot
    {
        $allocated = $firm->seatAllocations()
            ->where('seat_class', $seatClass->value)
            ->where('status', SeatAllocationStatus::Active->value)
            ->sum('seats_allocated');

        $used = $firm->firmUsers()
            ->where('status', FirmUserStatus::Active->value)
            ->get()
            ->filter(fn ($firmUser) => $firmUser->effectiveSeatClass() === $seatClass)
            ->count();

        return new SeatUsageSnapshot($seatClass, (int) $allocated, $used);
    }

    /**
     * "Block the invite with a clear pool-exhausted message" (PDF edge
     * case) — callers use this BEFORE creating the new FirmUser row.
     */
    public function canInvite(Firm $firm, SeatClass $seatClass): bool
    {
        return $this->usageFor($firm, $seatClass)->remaining() > 0;
    }
}
