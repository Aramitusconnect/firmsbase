<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Filament\Firm\Widgets\PlaidFirmOverviewSummaryCardsWidget;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
use App\Services\IntegrationEntitlementPolicyService;
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
 * PlaidOverviewPage — FirmsVault Live Integrations, Checkpoint 4
 * ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §2). The entry point for
 * every other Firm Admin Plaid page; mirrors `IntegrationUsagePage`'s
 * shape. Gated by BOTH `IntegrationEntitlementPolicyService::isEnabled()`
 * (existing, generic) AND `PlaidEntitlementPolicyService::assertEnabled()`
 * (the new `module_code = 'plaid'` add-on gate), mirroring
 * `FirmIntegrationResource::isFirmEntitled()`'s existing two-clause `&&`
 * shape.
 *
 * FOUND AND FIXED (Checkpoint 7 authorization review, item 19): the
 * entitlement checks above answer "has this firm purchased Plaid," not
 * "may this firm user view Plaid connection health/status" — no role
 * check was present at all, so any active firm user of any role
 * (including Receptionist) could reach this page. This is a
 * financial-tier connection view, covered by
 * `FinancialIntegrationAccessPolicyService::canView()`'s documented
 * ceiling (FirmOwner, Attorney, BillingStaff ONLY — narrower than the
 * non-financial tier), never `IntegrationAccessPolicyService`'s wider
 * one. Added here, matching `IntegrationUsagePage::canAccess()`'s
 * established shape.
 */
class PlaidOverviewPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Plaid Overview';

    protected static ?string $title = 'Plaid Overview';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    public static function canAccess(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(IntegrationEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(PlaidEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(FinancialIntegrationAccessPolicyService::class)->canView($firmUser->role);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function getHeaderWidgets(): array
    {
        return [
            PlaidFirmOverviewSummaryCardsWidget::class,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): Collection {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null || ! app(FinancialIntegrationAccessPolicyService::class)->canView($firmUser->role)) {
                    return collect();
                }

                return FirmIntegration::query()
                    ->where('firm_id', $firmUser->firm_id)
                    ->whereHas('integrationProvider', fn ($q) => $q->where('code', ProviderKey::Plaid->value))
                    ->with('integrationProvider')
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn (FirmIntegration $item): array => [
                        'id' => $item->uuid,
                        'display_label' => $item->display_label ?? 'Untitled connection',
                        'status' => is_object($item->status) ? $item->status->value : $item->status,
                        'connected_at' => $item->connected_at,
                        'last_health_status' => is_object($item->last_health_status) ? $item->last_health_status?->value : $item->last_health_status,
                    ]);
            })
            ->columns([
                TextColumn::make('display_label')->label('Connection'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'gray',
                        'scope_insufficient', 'reauthorization_required' => 'warning',
                        'error' => 'danger',
                        'disconnected' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('connected_at')->dateTime()->placeholder('—'),
                TextColumn::make('last_health_status')->label('Health')->badge()->placeholder('—'),
            ])
            ->emptyStateHeading('No Plaid connections yet')
            ->emptyStateDescription('Connect a financial account to a matter to see it here.')
            ->paginated(false);
    }
}
