<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Filament\Firm\Pages\AccountingOverviewPage\Actions\ClosePeriodAction;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\AccountingReportingService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * AccountingOverviewPage — Phase L. A read-only Filament Page
 * (deliberately not a Resource — no single underlying model), wired
 * directly to AccountingReportingService: nothing on this page computes
 * a financial figure itself, it only renders what that service
 * returns, per the master prompt's own "do not put financial
 * calculation logic directly inside Filament pages" rule.
 *
 * "Close Period" is the one money/state-changing operation reachable
 * from this page, and it is an explicit named Action
 * (ClosePeriodAction) invoking AccountingPeriodCloseService::close()
 * directly — never a generic form/CRUD control.
 */
class AccountingOverviewPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Overview';

    protected static string|\UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Accounting Overview';

    public static function canAccess(): bool
    {
        return static::isFirmAccountingEligible();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    private static function isFirmAccountingEligible(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(AccountingEntitlementPolicyService::class)->isExpensesEnabledForFirm($firmUser->firm)
            && app(AccountingEntitlementPolicyService::class)->canApprove($firmUser->role);
    }

    protected function getHeaderActions(): array
    {
        return [
            ClosePeriodAction::make(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Accounts Receivable Aging')
                ->schema([
                    Text::make(fn (): string => $this->arAgingSummary())
                        ->size(TextSize::Medium),
                ]),
            Section::make('Earned vs. Unearned')
                ->schema([
                    Text::make(fn (): string => $this->earnedVsUnearnedSummary())
                        ->size(TextSize::Medium),
                ]),
            Section::make('Reconciliation Exceptions')
                ->schema([
                    Text::make(fn (): string => $this->reconciliationExceptionsSummary())
                        ->size(TextSize::Medium)
                        ->weight(fn (): FontWeight => $this->hasReconciliationExceptions() ? FontWeight::Bold : FontWeight::Normal),
                ]),
        ]);
    }

    private function arAgingSummary(): string
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return 'No active firm.';
        }

        $report = app(AccountingReportingService::class)->accountsReceivableAging($firmUser->firm);
        $totalCents = $report->data->sum('remaining_cents');
        $count = $report->data->count();

        return "{$count} unpaid invoice(s) totaling $".number_format($totalCents / 100, 2).' outstanding.';
    }

    private function earnedVsUnearnedSummary(): string
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return 'No active firm.';
        }

        $report = app(AccountingReportingService::class)->earnedVsUnearned($firmUser->firm);
        $unearnedTotal = $report->data->sum('unearned_cents');
        $earnedTotal = $report->data->sum('earned_cents');

        return 'Unearned (held in trust): $'.number_format($unearnedTotal / 100, 2).' — Earned to date: $'.number_format($earnedTotal / 100, 2);
    }

    private function reconciliationExceptionsSummary(): string
    {
        if (! $this->hasReconciliationExceptions()) {
            return 'No unresolved reconciliation discrepancies.';
        }

        $firmUser = Auth::user()?->activeFirmUser();
        $count = app(AccountingReportingService::class)->reconciliationExceptions($firmUser->firm)->data->count();

        return "{$count} unresolved trust reconciliation discrepancy(ies) require review.";
    }

    private function hasReconciliationExceptions(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(AccountingReportingService::class)->reconciliationExceptions($firmUser->firm)->data->isNotEmpty();
    }
}
