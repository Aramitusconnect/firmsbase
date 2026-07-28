<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

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
