<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Enums\ChartOfAccountPurpose;
use App\Filament\Firm\Pages\AccountingOverviewPage\Actions\ClosePeriodAction;
use App\Models\Invoice;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\AccountingReportingService;
use App\Services\ChartOfAccountsService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
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
class AccountingOverviewPage extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

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

    /**
     * The purposes real posting code (OperatingJournalRecorderService,
     * AccountingOpeningBalanceService) actually throws
     * AccountingSetupIncompleteException for when unconfigured — Trust &
     * Accounting Integrity Hardening, Mission 1.4. Kept here, not
     * duplicated inside ChartOfAccountsService or the enum itself, since
     * this list is "what does this firm need today for the flows it can
     * reach," a UI-onboarding concern, not a domain rule.
     */
    private const REQUIRED_PURPOSES = [
        ChartOfAccountPurpose::OperatingCash,
        ChartOfAccountPurpose::LegalFeeRevenue,
        ChartOfAccountPurpose::CostReimbursementRevenue,
        ChartOfAccountPurpose::GeneralOperatingExpense,
        ChartOfAccountPurpose::UnappliedOperatingFundsLiability,
        ChartOfAccountPurpose::OpeningBalanceEquity,
    ];

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Chart of Accounts Setup')
                ->description('These purposes are required by real accounting/billing activity. A missing purpose blocks the specific action that needs it (e.g. applying a payment) until it is configured.')
                ->schema([
                    Text::make(fn (): string => $this->chartOfAccountsSetupSummary())
                        ->size(TextSize::Medium)
                        ->weight(fn (): FontWeight => $this->hasMissingRequiredPurposes() ? FontWeight::Bold : FontWeight::Normal),
                ]),
            Section::make('Accounts Receivable Aging')
                ->schema([
                    Text::make(fn (): string => $this->arAgingSummary())
                        ->size(TextSize::Medium),
                ]),
            Section::make('Accounts Receivable Aging Detail')
                ->description('Every unpaid invoice, bucketed by days overdue relative to its due date.')
                ->collapsible()
                ->schema([EmbeddedTable::make()]),
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

    /**
     * The bucket-level detail behind arAgingSummary()'s collapsed
     * sentence — same AccountingReportingService::accountsReceivableAging()
     * call, rendered as a real per-invoice table instead of only a
     * total. Array-row-backed ->records() table (mirrors
     * PlatformAutomationOversightPage's established shape for a plain
     * array/collection with no single backing Eloquent model) —
     * ->recordAction(null)->recordUrl(null) disables Filament's default
     * row-click resolution against these array rows for the same
     * reason that page's own docblock documents.
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->arAgingRows())
            ->columns([
                TextColumn::make('invoice.id')
                    ->label('Invoice')
                    ->formatStateUsing(fn ($state): string => "#{$state}"),
                TextColumn::make('invoice.client.display_name')
                    ->label('Client')
                    ->placeholder('—'),
                TextColumn::make('remaining_cents')
                    ->label('Remaining')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                TextColumn::make('days_overdue')
                    ->label('Days Overdue')
                    ->alignEnd(),
                TextColumn::make('bucket')
                    ->label('Bucket')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => (string) str($state)->headline())
                    ->color(fn (string $state): string => match ($state) {
                        'current' => 'success',
                        '1_30' => 'info',
                        '31_60' => 'warning',
                        '61_90', '90_plus' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->emptyStateHeading('No unpaid invoices')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated(false);
    }

    /**
     * @return Collection<int, array{invoice: Invoice, remaining_cents: int, days_overdue: int, bucket: string}>
     */
    private function arAgingRows(): Collection
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return collect();
        }

        return app(AccountingReportingService::class)->accountsReceivableAging($firmUser->firm)->data;
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

    private function missingRequiredPurposes(): array
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return [];
        }

        $chartOfAccounts = app(ChartOfAccountsService::class);

        return collect(self::REQUIRED_PURPOSES)
            ->reject(fn (ChartOfAccountPurpose $purpose): bool => $chartOfAccounts->resolveByPurpose($firmUser->firm, $purpose) !== null)
            ->values()
            ->all();
    }

    private function hasMissingRequiredPurposes(): bool
    {
        return $this->missingRequiredPurposes() !== [];
    }

    private function chartOfAccountsSetupSummary(): string
    {
        $missing = $this->missingRequiredPurposes();

        if ($missing === []) {
            return 'All required accounting purposes are configured.';
        }

        $labels = collect($missing)->map(fn (ChartOfAccountPurpose $purpose): string => (string) str($purpose->value)->headline())->implode(', ');

        return "Missing required accounts for: {$labels}. Go to Accounting → Chart of Accounts to create them.";
    }
}
