<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Integrations\Services\PlaidFirmUsageCostSummaryService;
use App\Services\PlaidEntitlementPolicyService;
use BackedEnum;
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

/**
 * PlaidUsagePage — FirmsVault Live Integrations, Checkpoint 4 ("Plaid
 * financial evidence add-on"; checkpoint4-design-workspace-and-admin-ui.md
 * §2). `IntegrationUsagePage`'s exact shape, reading
 * `PlaidFirmUsageCostSummaryService`, honoring the "null cost is
 * 'unknown,' never coalesced to $0" discipline.
 */
class PlaidUsagePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Plaid Usage & Cost';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $title = 'Plaid Usage & Estimated Cost';

    public static function canAccess(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(PlaidEntitlementPolicyService::class)->isEnabled($firmUser->firm);
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
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null) {
                    return collect();
                }

                return app(PlaidFirmUsageCostSummaryService::class)->summariesForFirm((int) $firmUser->firm_id);
            })
            ->columns([
                TextColumn::make('product'),
                TextColumn::make('billing_operation')->label('Operation'),
                TextColumn::make('environment'),
                TextColumn::make('status')->badge(),
                TextColumn::make('call_count')->label('Calls')->alignEnd(),
                TextColumn::make('total_quantity')->label('Quantity')->alignEnd(),
                TextColumn::make('total_estimated_cents')
                    ->label('Estimated cost')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'Unknown' : '$'.number_format($state / 100, 2))
                    ->alignEnd(),
                TextColumn::make('last_reserved_at')->label('Last activity')->dateTime()->placeholder('—'),
            ])
            ->emptyStateHeading('No Plaid usage has been recorded yet')
            ->paginated(false);
    }
}
