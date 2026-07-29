<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Integrations\Services\IntegrationAccessPolicyService;
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
 *
 * FOUND AND FIXED (Checkpoint 7 authorization review, item 19): the
 * entitlement check answered only "has this firm purchased Plaid," not
 * "may this firm user view estimated cost/billing impact" — this page
 * renders per-product dollar cost estimates, exactly the
 * usage/billing-impact ceiling `IntegrationAccessPolicyService::canViewUsage()`
 * exists to gate (FirmOwner, BillingStaff — deliberately narrower than
 * the health/activity ceiling, no Attorney; identical to the
 * non-financial tier per `FinancialIntegrationAccessPolicyService`'s own
 * docblock, so the non-financial service is reused here rather than
 * duplicated). Matches `IntegrationUsagePage::canAccess()`'s established
 * shape — this page's own docblock already claimed to be that page's
 * "exact shape" but omitted exactly this check.
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

        return $firmUser !== null
            && app(PlaidEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(IntegrationAccessPolicyService::class)->canViewUsage($firmUser->role);
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

                if ($firmUser === null || ! app(IntegrationAccessPolicyService::class)->canViewUsage($firmUser->role)) {
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
