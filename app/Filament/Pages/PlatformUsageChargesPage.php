<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\UsageRollupMetric;
use App\Models\BillingAccount;
use App\Models\PlatformAdmin;
use App\Models\UsageRollup;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlatformUsageChargesPage — Phase 3 (FirmsVault Platform Admin Control
 * Center, "Billing and Commercial Administration"). A READ-ONLY page
 * (not a mutable Resource — mirrors PlatformProviderHealthPage's own
 * `Page implements HasTable` shape) over `usage_rollups`.
 *
 * `usage_rollups` carries no RLS at all (Global — confirmed by the
 * Phase 3 architecture investigation), so an ordinary Eloquent
 * `->query()` table is correct here, exactly like
 * PlatformProviderHealthPage's own precedent for a plain, no-RLS
 * summary table.
 *
 * A null `firm_id` row is the billing-account-level aggregate for that
 * metric/period; a non-null `firm_id` row is one member firm's
 * contribution (see UsageRollup::isAccountLevelAggregate()) — this is
 * attribution, NOT a tenant boundary (no BelongsToTenant on this
 * model). The "Scope" column below renders this distinction plainly
 * rather than leaving it implicit in a nullable column.
 *
 * NO mutation of any kind is exposed here. UsageRollupService::
 * recordUsage() is create-only with no update/delete/adjustment concept
 * modeled anywhere in this codebase — "adjust a usage charge" has no
 * existing correction path beyond recording a new/offsetting row, which
 * the service does not treat as a first-class operation. Building a
 * usage-charge mutation action here would fabricate commercial behavior
 * this codebase's backend does not support — see this page's own
 * empty-state/description copy for the same disclosure surfaced to the
 * admin using it.
 */
class PlatformUsageChargesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'Usage Charges';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing & Commercial';

    protected static ?int $navigationSort = 42;

    protected static ?string $title = 'Usage Charges';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessPlatformBilling($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('About This Page')
                ->icon(Heroicon::OutlinedInformationCircle)
                ->collapsible()
                ->schema([
                    Text::make(
                        'These are system-recorded usage figures written by UsageRollupService::recordUsage() '.
                        '(the only place usage_rollups rows are created). There is no manual adjustment or '.
                        'correction tool for usage charges in this phase — recordUsage() only ever inserts new '.
                        'rows; it has no update/delete/adjustment concept to expose as an admin action.'
                    ),
                    /**
                     * Billing & Commercial Control Plane pass. Three
                     * truths an operator on a page titled "Usage
                     * Charges" would otherwise assume, all wrong.
                     *
                     * Verified against the usage_rollups migration at
                     * this pass's HEAD: the table holds
                     * billing_account_id, firm_id, metric,
                     * period_starts_at, period_ends_at, quantity, and
                     * unit. There is no unit price, rate, charge,
                     * currency, invoice_id, priced flag, or finalized
                     * flag anywhere on it.
                     */
                    Text::make(
                        'No money is shown on this page, because none is recorded. A usage row holds a quantity '.
                        'for a metric over a period — it carries no unit price, rate, charge amount, currency, or '.
                        'link to an invoice. So there is no priced, unpriced, billable, unbilled, or invoiced '.
                        'usage state to report, and no such state is shown. Despite the page title, nothing here '.
                        'has yet been charged to anyone.'
                    ),
                    Text::make(
                        'A row with no firm is the billing-account-level aggregate for that metric and period — '.
                        'not an unattributed orphan. It is labelled "Account-level" rather than "Unallocated", '.
                        'because calling it unallocated would describe a data-quality problem that is not '.
                        'happening.'
                    ),
                    Text::make(
                        'This is platform billable usage, keyed to a billing account. It is a different thing '.
                        'from integration provider telemetry and from what FirmsVault itself pays an upstream '.
                        'provider — those are operational cost figures, live under Integrations, and never become '.
                        'usage here without a deliberate metering call.'
                    ),
                    Text::make(
                        'Usage records are immutable and there is no adjustment ledger. A mis-recorded quantity '.
                        'cannot be corrected from this console, and it is deliberately not editable in place: '.
                        'editing the original row would destroy the evidence of what was actually observed. A '.
                        'correction would need a separate, additive adjustment record, and no such mechanism '.
                        'exists.'
                    ),
                ]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                // Re-checked at render time, not only at page-load
                // canAccess() — matches PlatformProviderHealthPage's own
                // established discipline.
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return UsageRollup::query()->whereRaw('1 = 0');
                }

                if (! app(PlatformStaffAccessPolicyService::class)->canAccessPlatformBilling($admin)->allowed) {
                    return UsageRollup::query()->whereRaw('1 = 0');
                }

                return UsageRollup::query()->with(['billingAccount', 'firm']);
            })
            ->filters([
                SelectFilter::make('billing_account_id')
                    ->label('Billing account')
                    ->searchable()
                    ->options(fn (): array => BillingAccount::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('metric')
                    ->options(collect(UsageRollupMetric::cases())
                        ->mapWithKeys(fn (UsageRollupMetric $metric): array => [$metric->value => Str::headline($metric->value)])
                        ->all()),
                Filter::make('period')
                    ->label('Period starts between')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('period_starts_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('period_starts_at', '<=', $date));
                    }),
            ])
            ->columns([
                TextColumn::make('billingAccount.name')->label('Billing account')->searchable()->sortable(),
                TextColumn::make('metric')
                    ->badge()
                    ->formatStateUsing(fn (UsageRollupMetric $state): string => Str::headline($state->value))
                    ->sortable(),
                IconColumn::make('is_account_level')
                    ->label('Scope')
                    ->state(fn (UsageRollup $record): bool => $record->isAccountLevelAggregate())
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedBuildingOffice2)
                    ->falseIcon(Heroicon::OutlinedBuildingOffice)
                    ->tooltip(fn (UsageRollup $record): string => $record->isAccountLevelAggregate()
                        ? 'Account-level aggregate'
                        : 'Per-firm attribution: '.($record->firm?->name ?? 'unknown firm')),
                TextColumn::make('firm.name')->label('Firm (if attributed)')->placeholder('Account-level'),
                TextColumn::make('period_starts_at')->label('Period start')->date()->sortable(),
                TextColumn::make('period_ends_at')->label('Period end')->date()->sortable(),
                TextColumn::make('quantity')->alignEnd()->sortable(),
                TextColumn::make('unit')->placeholder('—'),
            ])
            ->emptyStateHeading('No usage charges recorded yet')
            ->emptyStateDescription('These are system-recorded figures with no manual adjustment tool in this phase.')
            ->defaultSort('period_starts_at', 'desc')
            ->paginated([25, 50, 100]);
    }
}
