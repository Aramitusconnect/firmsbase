<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserStatus;
use App\Models\Firm;

/**
 * FirmSeatCapacityService — Firm Feature Manifest §12's flat per-firm
 * seat model. A NEW, SEPARATE concern from `SeatEnforcementService`/
 * `SeatAllocationService`/`SeatPool`/`SeatAllocation`/`SeatClass` — this
 * service is not a modification of any of those, and does not read or
 * write `seat_allocations`/`seat_pools` at all.
 *
 * WHY A SEPARATE SERVICE, NOT A CHANGE TO SeatEnforcementService: the
 * per-`SeatClass` (Attorney/Staff/ReadOnly) architecture requires a
 * SEPARATE purchased quantity per class. The confirmed business model
 * (Firm Feature Manifest §12) is the opposite: each firm purchases ONE
 * flat seat quantity, and every `FirmUser` — any of the 6
 * `FirmUserRole` values, including `FirmOwner` — consumes exactly one
 * seat regardless of role/class. There is no clean way to represent
 * "one flat number" across 3 mandatory `SeatClass` rows in
 * `seat_allocations` without either triple-counting capacity (writing
 * the same number into all 3 classes and then summing them, which
 * would let a 10-seat firm invite 30 people) or arbitrarily picking one
 * class to hold the whole quantity (which would misrepresent
 * `SeatAllocation`'s own per-class meaning to any other reader of that
 * table) — both strictly worse than a small, separate, flat-capacity
 * concern. `SeatAllocation`/`SeatPool`/`SeatAllocationService` are left
 * completely untouched/dormant (confirmed, by direct source re-scan,
 * to have no other live production consumer beyond
 * `SeatEnforcementService::usageFor()`/`canInvite()`, which is itself
 * only called by `DowngradeEvaluationService::evaluate()` — a
 * read-only computation with no real production caller of its own
 * today, see that service's own docblock) — preserved for future
 * per-class authorization/accounting compatibility, never wired into
 * this flat model.
 *
 * SOURCE OF TRUTH: `firm_licenses.purchased_seats` (added by
 * `2026_08_08_100010_add_purchased_seats_to_firm_licenses_table`) is
 * the firm's purchased quantity — a plain, mutable, nullable integer.
 * NULL means "no purchased-seat quantity configured for this license"
 * (a plan-less firm, or a legacy commercial firm not yet backfilled by
 * `firms:report-missing-purchased-seats --apply`) — never treated as
 * zero (which would read as "correctly configured with zero seats") or
 * as unlimited (which would silently bypass enforcement).
 *
 * USED SEATS: every `firm_users` row whose `status` is `Active`,
 * `Invited`, or `Suspended` counts as one used seat — the Firm Owner is
 * a completely ordinary `FirmUser` row like any other and counts like
 * any other (confirmed business decision: a 10-seat firm has 1 used by
 * the owner alone). `Invited` counts because a pending invitation
 * reserves the seat it would consume once accepted — otherwise a firm
 * could send unlimited invitations past its purchased quantity and
 * only find out at acceptance time. `Suspended` counts (a JUDGMENT
 * CALL, not explicitly specified by the product owner, documented here
 * per that instruction) — suspension is administrative/temporary
 * (matching typical SaaS seat semantics: the person's access is
 * paused, not their employment/membership ended), so the seat remains
 * reserved for them rather than being handed to someone else while
 * they are merely suspended. Only `Removed` frees the seat — this
 * falls out naturally from the count-based check (a `Removed` row is
 * simply excluded), with no explicit "release" action and no deletion
 * of any historical/audit row.
 *
 * TENANT CONTEXT: both `firm_licenses` and `firm_users` are
 * `BelongsToTenant` + FORCE ROW LEVEL SECURITY. Every read below
 * self-wraps its own `TenantContextService::runWithFirmContext()` call
 * — unlike `SeatEnforcementService`'s deliberate no-self-wrap design
 * (which exists so a caller can compose a check with a subsequent
 * write inside ONE outer transaction), every method here is a pure,
 * standalone read with nothing to compose against a later write in the
 * same wrap, so self-wrapping is simpler and safe: nested
 * `runWithFirmContext()` calls restore the outer context correctly
 * (see that service's own docblock) even when called from inside
 * `FirmUserInvitationService::invite()`'s own outer wrap.
 */
class FirmSeatCapacityService
{
    /**
     * @var list<string>
     */
    private const SEAT_CONSUMING_STATUSES = [
        FirmUserStatus::Active->value,
        FirmUserStatus::Invited->value,
        FirmUserStatus::Suspended->value,
    ];

    /**
     * The firm's purchased flat seat quantity, or null if no license
     * exists, or the license exists but has no purchased-seat quantity
     * configured yet.
     */
    public function purchasedSeats(Firm $firm): ?int
    {
        return (new TenantContextService)->runWithFirmContext(
            $firm,
            fn (): ?int => $firm->license()->first()?->purchased_seats,
        );
    }

    /**
     * Every firm_users row that currently reserves a seat: Active,
     * Invited (a pending invitation reserves the seat), or Suspended
     * (administrative/temporary — see this class's own docblock).
     * Removed rows are excluded, which is how a removed member's seat
     * is freed — no separate release action exists or is needed.
     */
    public function usedSeats(Firm $firm): int
    {
        return (new TenantContextService)->runWithFirmContext(
            $firm,
            fn (): int => $firm->firmUsers()
                ->whereIn('status', self::SEAT_CONSUMING_STATUSES)
                ->count(),
        );
    }

    /**
     * Null when the firm has no purchased-seat quantity configured
     * (never a negative number, and never treated as unlimited).
     */
    public function remainingSeats(Firm $firm): ?int
    {
        $purchased = $this->purchasedSeats($firm);

        if ($purchased === null) {
            return null;
        }

        return max(0, $purchased - $this->usedSeats($firm));
    }

    /**
     * False whenever no purchased-seat quantity is configured at all
     * (a freshly-provisioned firm with no license, or a plan-less
     * firm) — never silently permissive.
     */
    public function canInvite(Firm $firm): bool
    {
        $purchased = $this->purchasedSeats($firm);

        if ($purchased === null) {
            return false;
        }

        return $this->usedSeats($firm) < $purchased;
    }
}
