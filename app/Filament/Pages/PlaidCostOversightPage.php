<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\Integrations\IntegrationDisplay;
use App\Integrations\Services\PlatformPlaidCostOversightReadService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * PlaidCostOversightPage — FirmsVault Live Integrations, Checkpoint 4
 * ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §3). Mirrors
 * `PlatformIntegrationOverviewPage`'s shape, reading
 * `PlatformPlaidCostOversightReadService`, which aggregates
 * `provider_billable_call_reservations`/`provider_rate_card_entries`
 * BY FIRM, never by individual transaction — every number on this page
 * is a SUM/COUNT, never a drill-down into what was purchased.
 */
class PlaidCostOversightPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $navigationLabel = 'Plaid Cost Oversight';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 11;

    protected static ?string $title = 'Plaid Cost Oversight';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters): Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                $filters ??= [];
                [$from, $to] = self::resolvePeriod($filters);

                try {
                    return app(PlatformPlaidCostOversightReadService::class)->overviewByFirm($admin, $from, $to);
                } catch (RuntimeException $e) {
                    Notification::make()->title('Not permitted')->body($e->getMessage())->danger()->send();

                    return collect();
                }
            })
            ->filters([
                // Period selection (§54). "All time" stays the default so
                // this page's long-standing behaviour is unchanged unless
                // an operator deliberately narrows it.
                SelectFilter::make('period')
                    ->label('Period')
                    ->options([
                        'mtd' => 'Month to date',
                        'previous_month' => 'Previous month',
                        'last_30' => 'Last 30 days',
                        'last_90' => 'Last 90 days',
                    ])
                    ->placeholder('All time'),
                Filter::make('custom_period')
                    ->label('Custom period')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('to')->label('To'),
                    ]),
            ])
            ->columns([
                TextColumn::make('firm_name')
                    ->label('Firm')
                    ->description(fn (array $record): string => (string) ($record['firm_uuid'] ?? '')),
                TextColumn::make('allocated_call_count')->label('Priced Calls')->alignEnd(),
                TextColumn::make('estimated_customer_cost_cents')
                    ->label('Estimated Cost')
                    // PRICING HONESTY (§9/§57). Three genuinely different
                    // facts, three different renderings — never one
                    // collapsed "$0.00":
                    //   - null total  => no priced call at all in this
                    //     window, so there is no estimate to state.
                    //   - a real sum  => shown with the rate cards' own
                    //     currency, never a hardcoded symbol.
                    //   - a real zero => shown as a currency zero, which
                    //     here genuinely means "priced, and the price is
                    //     nothing".
                    ->formatStateUsing(function (?int $state): string {
                        if ($state === null) {
                            return 'No priced usage';
                        }

                        return self::formatMoney($state);
                    })
                    ->description(fn (array $record): string => ((int) ($record['unallocated_call_count'] ?? 0)) > 0
                        ? 'Partial estimate — some usage is unpriced'
                        : 'Estimated — not invoiced')
                    ->alignEnd(),
                TextColumn::make('unallocated_call_count')
                    ->label('Unallocated Usage')
                    // Unpriced usage stays UNPRICED. It is deliberately
                    // never folded into the estimated cost above, because
                    // doing so would require inventing a price for a call
                    // no rate card covers.
                    ->formatStateUsing(fn (?int $state): string => ((int) $state) === 0
                        ? '0'
                        : $state.' (no rate card — cost unknown)')
                    ->color(fn (?int $state): string => ((int) $state) > 0 ? 'warning' : 'gray')
                    ->alignEnd(),
                TextColumn::make('live_balance_call_count')->label('Live Balance Calls')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_call_count')->label('Total Calls')->alignEnd(),
            ])
            ->emptyStateHeading('No Plaid usage has been recorded for the selected period')
            ->emptyStateDescription('These figures are estimated upstream provider usage costs. They are never invoiced to a firm and never appear on a customer invoice.')
            ->paginated([25, 50]);
    }

    /**
     * Header note stating, once and unmissably, what these numbers are
     * and are not (§57) — plus where the pricing behind them came from,
     * so a reader can tell a complete estimate from a partial one.
     */
    public function getSubheading(): ?string
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return null;
        }

        try {
            $provenance = app(PlatformPlaidCostOversightReadService::class)->pricingProvenance($admin);
        } catch (RuntimeException) {
            return null;
        }

        $base = 'Estimated upstream Plaid provider cost — not invoiced. '
            .'These figures never create, change, or appear on a firm invoice; customer billing is governed separately.';

        if (! $provenance['has_pricing']) {
            return $base.' Pricing unavailable: no Plaid rate card is currently in effect, so every call is reported as unallocated usage rather than priced at zero.';
        }

        $currency = $provenance['currencies'] === []
            ? IntegrationDisplay::UNKNOWN
            : implode(', ', $provenance['currencies']);

        $effective = $provenance['effective_from'] ?? IntegrationDisplay::UNKNOWN;

        return $base." Pricing source: {$provenance['entry_count']} Plaid rate card entr(ies) in effect · currency {$currency} · latest effective from {$effective}.";
    }

    /**
     * Formats a cent total using the currency the Plaid rate cards are
     * genuinely denominated in. Falls back to a plain, unsymboled
     * amount when the rate cards do not agree on one currency — an
     * invented "$" on a EUR-denominated rate card would be a fabricated
     * fact, small but real.
     */
    private static function formatMoney(int $cents): string
    {
        $admin = Auth::guard('platform_admin')->user();

        $currencies = [];

        if ($admin instanceof PlatformAdmin) {
            try {
                $currencies = app(PlatformPlaidCostOversightReadService::class)->pricingProvenance($admin)['currencies'];
            } catch (RuntimeException) {
                $currencies = [];
            }
        }

        $amount = number_format($cents / 100, 2);

        if (count($currencies) === 1) {
            return $amount.' '.strtoupper((string) $currencies[0]);
        }

        return $amount.($currencies === [] ? '' : ' (mixed currencies)');
    }

    /**
     * Translates the period filter into concrete reserved_at bounds. An
     * explicit custom from/to always wins over the named-period select,
     * so a half-set custom range is never silently combined with a
     * preset into a window the operator did not ask for.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: ?string, 1: ?string}
     */
    private static function resolvePeriod(array $filters): array
    {
        $customFrom = $filters['custom_period']['from'] ?? null;
        $customTo = $filters['custom_period']['to'] ?? null;

        if (filled($customFrom) || filled($customTo)) {
            return [
                filled($customFrom) ? (string) $customFrom : null,
                filled($customTo) ? (string) $customTo : null,
            ];
        }

        $now = CarbonImmutable::now();

        return match ($filters['period']['value'] ?? null) {
            'mtd' => [$now->startOfMonth()->toDateString(), $now->toDateString()],
            'previous_month' => [
                $now->subMonthNoOverflow()->startOfMonth()->toDateString(),
                $now->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            'last_30' => [$now->subDays(29)->toDateString(), $now->toDateString()],
            'last_90' => [$now->subDays(89)->toDateString(), $now->toDateString()],
            default => [null, null],
        };
    }
}
