<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Integrations\Services\PlatformPlaidCostOversightReadService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
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
            ->records(function (): Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                try {
                    return app(PlatformPlaidCostOversightReadService::class)->overviewByFirm($admin);
                } catch (RuntimeException $e) {
                    Notification::make()->title('Not permitted')->body($e->getMessage())->danger()->send();

                    return collect();
                }
            })
            ->columns([
                TextColumn::make('firm_name')->label('Firm'),
                TextColumn::make('allocated_call_count')->label('Priced calls')->alignEnd(),
                TextColumn::make('estimated_customer_cost_cents')
                    ->label('Estimated cost')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'Unknown' : '$'.number_format($state / 100, 2))
                    ->alignEnd(),
                TextColumn::make('unallocated_call_count')->label('Unallocated usage')->alignEnd(),
                TextColumn::make('live_balance_call_count')->label('Live Balance calls')->alignEnd(),
                TextColumn::make('total_call_count')->label('Total calls')->alignEnd(),
            ])
            ->emptyStateHeading('No Plaid usage recorded yet')
            ->paginated([25, 50]);
    }
}
