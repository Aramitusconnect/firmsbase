<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Filament\Firm\Resources\PlaidItemResource\Pages\ListPlaidItems;
use App\Filament\Firm\Resources\PlaidItemResource\Pages\ViewPlaidItem;
use App\Filament\Firm\Resources\PlaidItemResource\RelationManagers\AccountsRelationManager;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\PlaidEntitlementPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * PlaidItemResource — FirmsVault Live Integrations, Checkpoint 4
 * ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §2). A SIBLING resource
 * to `FirmIntegrationResource`, never a provider-branch inside it —
 * `FirmIntegrationResource` itself must stay provider-agnostic per
 * Checkpoint 3's own confirmed "no provider branch anywhere" discipline.
 * `$model = FirmIntegration::class`, scoped by `getEloquentQuery()`'s
 * provider filter (a query-level UX filter — `ViewPlaidItem::resolveRecord()`
 * re-checks the provider server-side too, never trusting the list-query
 * filter alone as the real boundary).
 */
class PlaidItemResource extends Resource
{
    protected static ?string $model = FirmIntegration::class;

    protected static ?string $slug = 'plaid-items';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Plaid Items';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $recordTitleAttribute = 'display_label';

    public static function canAccess(): bool
    {
        return parent::canAccess() && static::isFirmEntitled();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::isFirmEntitled();
    }

    public static function isFirmEntitled(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(IntegrationEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(PlaidEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(IntegrationAccessPolicyService::class)->canView($firmUser->role);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas(
            'integrationProvider',
            fn (Builder $query) => $query->where('code', ProviderKey::Plaid->value)
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_label')
                    ->label('Connection')
                    ->searchable()
                    ->default('Untitled connection'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'active' => 'success',
                        'pending' => 'gray',
                        'scope_insufficient', 'reauthorization_required' => 'warning',
                        'error' => 'danger',
                        'disconnected' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('connected_at')->dateTime()->sortable(),
                TextColumn::make('last_health_status')
                    ->label('Health')
                    ->badge()
                    ->formatStateUsing(fn ($state): ?string => $state === null ? null : (is_object($state) ? $state->value : (string) $state)),
                TextColumn::make('last_health_check_at')->label('Last sync')->since()->placeholder('Never'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'reauthorization_required' => 'Reauthorization required',
                        'disconnected' => 'Disconnected',
                        'error' => 'Error',
                        'scope_insufficient' => 'Scope insufficient',
                    ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            AccountsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaidItems::route('/'),
            'view' => ViewPlaidItem::route('/{record}'),
        ];
    }
}
