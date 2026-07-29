<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Models\TimelineEvent;
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
 * PlaidCostAlertsPage — FirmsVault Live Integrations, Checkpoint 4
 * ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §2). Reads `TimelineEvent`
 * rows filtered `event_type LIKE 'provider_billing.%'` for this firm —
 * the same hand-built `HasMany`-shaped read as the Matter/Client-Portal
 * track's own Activity tab.
 *
 * FOUND AND FIXED (Checkpoint 7 authorization review, item 19): billing
 * alert data is exactly the usage/billing-impact ceiling
 * `IntegrationAccessPolicyService::canViewUsage()` exists to gate
 * (FirmOwner, BillingStaff) — no role check was present at all before
 * this fix, only the "has this firm purchased Plaid" entitlement check.
 * Same ceiling and reasoning as `PlaidUsagePage`'s identical fix.
 */
class PlaidCostAlertsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Plaid Cost Alerts';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $title = 'Plaid Cost Alerts';

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

                return TimelineEvent::query()
                    ->where('firm_id', $firmUser->firm_id)
                    ->where('event_type', 'like', 'provider_billing.%')
                    ->orderByDesc('occurred_at')
                    ->limit(200)
                    ->get();
            })
            ->columns([
                TextColumn::make('event_type')->label('Alert'),
                TextColumn::make('occurred_at')->label('Occurred')->dateTime(),
                TextColumn::make('metadata_json')->label('Details')->formatStateUsing(fn (?array $state): string => $state !== null ? json_encode($state) : '—')->limit(80),
            ])
            ->emptyStateHeading('No cost alerts recorded')
            ->paginated([25, 50]);
    }
}
