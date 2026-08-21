<?php

declare(strict_types=1);

namespace App\Filament\Firm\Widgets;

use App\Services\FirmCommandCenterAggregationService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * CommandCenterOverviewWidget — Mission 5A (Firm Daily-Workflow
 * Completion). FirmCommandCenterAggregationService::snapshot() already
 * computes 15 real, firm-scoped operational metrics but had zero UI
 * consumers before this widget. Auto-registers via
 * FirmPanelProvider::discoverWidgets() (App\Filament\Firm\Widgets) —
 * no panel provider change needed.
 *
 * Mirrors PlaidFirmOverviewSummaryCardsWidget's exact shape: eager
 * ($isLazy = false, no polling), a static canView() gate reading
 * Auth::user()?->activeFirmUser(), and getStats() returning a plain
 * Stat::make(...) array. Unlike Plaid's widget, there is no dedicated
 * entitlement/access-policy service for this dashboard-overview data —
 * every underlying count is already firm-scoped (filtered by firm_id,
 * read inside the service's own runWithFirmContext() wraps), so the
 * gate here is simply "is this an authenticated, active firm staff
 * member" — the same non-boundary, UX-layer gate every list/dashboard
 * page in this panel applies before its real per-record boundaries (if
 * any) would apply; this widget shows aggregate counts only, never an
 * individual record.
 *
 * All 15 snapshot metrics exist, but rendering all 15 as separate
 * stat cards would overwhelm the dashboard (explicit mission
 * instruction: "don't cram all 15 onto one row"). Grouped into 3
 * cards — Intake, Deadlines & Tasks, Billing — each card's headline
 * value is its single most actionable count, with the rest of that
 * group's metrics folded into the description line. Deliberately
 * excludes mattersWaitingOnClientCount / mattersReadyForReviewCount /
 * documentsNeedingApprovalCount / formsReadyForReviewCount /
 * documentChaseEscalationsCount / inactiveClientsCount from this
 * summary view — real metrics, just not the subset chosen for a
 * three-card daily-workflow snapshot; nothing here is fabricated.
 */
class CommandCenterOverviewWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return Auth::user()?->activeFirmUser() !== null;
    }

    protected function getStats(): array
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return [];
        }

        $snapshot = app(FirmCommandCenterAggregationService::class)->snapshot($firmUser->firm);

        $deadlineIssues = $snapshot->overdueTasksCount + $snapshot->blockedTasksCount;
        $billingIssues = $snapshot->installmentsMissedCount + $snapshot->failedPaymentsCount;

        return [
            Stat::make('Intake — New Leads', (string) $snapshot->newLeadsCount)
                ->description("{$snapshot->consultationsCount} consultations scheduled")
                ->descriptionIcon(Heroicon::OutlinedCalendar)
                ->icon(Heroicon::OutlinedUserPlus)
                ->color($snapshot->newLeadsCount > 0 ? 'info' : 'gray'),

            Stat::make('Deadlines & Tasks — Due This Week', (string) $snapshot->deadlinesThisWeekCount)
                ->description("{$snapshot->overdueTasksCount} overdue · {$snapshot->blockedTasksCount} blocked")
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->icon(Heroicon::OutlinedClock)
                ->color($deadlineIssues > 0 ? 'danger' : 'success'),

            Stat::make('Billing — Unpaid Invoices', (string) $snapshot->unpaidInvoicesCount)
                ->description("{$snapshot->installmentsDueCount} installments due · {$snapshot->installmentsMissedCount} missed · {$snapshot->failedPaymentsCount} failed payments")
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->icon(Heroicon::OutlinedCreditCard)
                ->color($billingIssues > 0 ? 'danger' : ($snapshot->unpaidInvoicesCount > 0 ? 'warning' : 'success')),
        ];
    }
}
