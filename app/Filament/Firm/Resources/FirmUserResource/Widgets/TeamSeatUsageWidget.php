<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmUserResource\Widgets;

use App\Services\FirmMembershipAccessPolicyService;
use App\Services\FirmSeatCapacityService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * TeamSeatUsageWidget — ListFirmUsers header widget (Firm Feature
 * Manifest §12 flat per-firm seat model). Mirrors
 * `PlaidFirmOverviewSummaryCardsWidget`'s own "single-bounded-aggregate-
 * query, firm-scoped `StatsOverviewWidget`" shape — the established
 * convention in this panel for a small summary readout above a
 * resource's table.
 *
 * Shows "Team Members — X of Y seats used" when the firm has a
 * purchased-seat quantity configured, or a clear "no licensed seats
 * configured" message when `FirmSeatCapacityService::purchasedSeats()`
 * is null (no license, or a license with no seat quantity set) —
 * informational only, this widget never blocks anything itself. The
 * real enforcement is `FirmUserInvitationService::invite()`'s own
 * `FirmSeatLimitExceededException` (thrown from the service layer, not
 * merely a UI-level disable) — this widget exists so a FirmOwner can
 * see WHY an invite might fail before attempting it, matching this
 * mission's "action stays clickable, fails cleanly with a clear message"
 * convention (the same shape `FirmSeatLimitExceededException` already
 * used before this change) rather than the alternative "hide the action
 * entirely" convention used by `SecondApproveAdjustmentAction`'s
 * distinct-approver guard — hiding is appropriate there because a
 * zero-eligible-option Select has nothing useful to show; here, showing
 * the exact used/purchased counts (and leaving the button clickable) is
 * more informative than hiding "+ Invite Team Member" outright, and
 * still fails safely via the service-level guard if seats run out
 * between page load and submission (TOCTOU).
 */
class TeamSeatUsageWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(FirmMembershipAccessPolicyService::class)->canView($firmUser->role);
    }

    protected function getStats(): array
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return [];
        }

        $seatCapacity = app(FirmSeatCapacityService::class);
        $firm = $firmUser->firm;
        $purchased = $seatCapacity->purchasedSeats($firm);

        if ($purchased === null) {
            return [
                Stat::make('Team Members', 'No licensed seats configured')
                    ->description('Contact your administrator to set up seat licensing before inviting team members.')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('warning'),
            ];
        }

        $used = $seatCapacity->usedSeats($firm);
        $atCapacity = $used >= $purchased;

        return [
            Stat::make('Team Members', "{$used} of {$purchased} seats used")
                ->description($atCapacity
                    ? 'All licensed seats are in use. Remove or free a seat before inviting another team member.'
                    : ($purchased - $used).' seat(s) remaining')
                ->icon(Heroicon::OutlinedUsers)
                ->color($atCapacity ? 'danger' : 'success'),
        ];
    }
}
