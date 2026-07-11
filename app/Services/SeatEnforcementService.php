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
    /**
     * Section 39A-3L, Checkpoint 9 — pure read, deliberately NOT
     * self-wrapped in TenantContextService::runWithFirmContext(). Both
     * seat_allocations and firm_users are FORCE-RLS tables, so callers
     * are responsible for establishing tenant context around their
     * ENTIRE operation before calling this (see
     * DowngradeEvaluationService::evaluate() for the current local-wrap
     * example). A future caller of canInvite() (e.g. an invite
     * controller) will likely need to wrap a whole "check-then-create-
     * FirmUser" operation in one outer context — if this method
     * self-wrapped, that outer wrap would be prematurely cleared by this
     * method's own inner self-wrap the instant it returns, the same
     * nested "decoy wrap" bug documented elsewhere in this mission (see
     * TemplateUpgradeLogService's docblock).
     */
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
     *
     * Section 39A-3L, Checkpoint 9 — like usageFor() above, this is a
     * pure read with NO internal context handling. Any future caller
     * (especially an invite controller) is responsible for establishing
     * its own tenant context around its entire check-then-write
     * operation — this method must not self-wrap, so it never
     * prematurely clears that caller's outer context.
     */
    public function canInvite(Firm $firm, SeatClass $seatClass): bool
    {
        return $this->usageFor($firm, $seatClass)->remaining() > 0;
    }
}
