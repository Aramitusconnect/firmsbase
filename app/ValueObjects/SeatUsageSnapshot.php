<?php

namespace App\ValueObjects;

use App\Enums\SeatClass;

/**
 * SeatUsageSnapshot — the result of SeatEnforcementService's usage
 * computation for one seat class, for one firm. "allocated" is the
 * firm's own direct seat_allocations total for this class PLUS any
 * pooled seats it can draw from (computed by SeatAllocationService,
 * not this VO); "used" is the count of firm_users whose
 * effectiveSeatClass() resolves to this class and whose status is
 * active. Client portal users are never included in "used" — they are
 * Client rows, never FirmUser rows, so they cannot appear here at all.
 */
final readonly class SeatUsageSnapshot
{
    public function __construct(
        public SeatClass $seatClass,
        public int $allocated,
        public int $used,
    ) {
    }

    public function remaining(): int
    {
        return max(0, $this->allocated - $this->used);
    }

    public function isExhausted(): bool
    {
        return $this->used >= $this->allocated;
    }

    public function isOverAllocated(): bool
    {
        return $this->used > $this->allocated;
    }
}
