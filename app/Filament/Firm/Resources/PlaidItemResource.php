<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Filament\Firm\Resources\PlaidItemResource\Pages\ListPlaidItems;
use App\Filament\Firm\Resources\PlaidItemResource\Pages\ViewPlaidItem;
use App\Filament\Firm\Resources\PlaidItemResource\RelationManagers\AccountsRelationManager;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\PlaidEntitlementPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
 *
 * FOUND AND FIXED (Checkpoint 7 authorization review, item 19 —
 * missed on the first pass across the Plaid admin surface, unlike its
 * every sibling Plaid page): this resource previously gated on
 * `IntegrationAccessPolicyService::canView()` — the NON-financial tier
 * (FirmOwner/Attorney/Paralegal/LegalAssistant) — for a financial-tier
 * connection view. Corrected to
 * `FinancialIntegrationAccessPolicyService::canView()` (FirmOwner,
 * Attorney, BillingStaff ONLY — narrower; no Paralegal/LegalAssistant),
 * matching `PlaidOverviewPage::canAccess()`'s established shape exactly.
 * Because `$model = FirmIntegration::class` is shared with
 * `FirmIntegrationResource`, this resource ALSO shares
 * `FirmIntegrationPolicy` (the standard Laravel policy Filament
 * consults for `canViewAny()`/`canView($record)` by default) —  that
 * policy itself is wired to the non-financial-tier
 * `IntegrationAccessPolicyService`, wrong for this financial-tier
 * resource. `canViewAny()`/`canView()` below are therefore explicitly
 * overridden rather than left to that shared, wrong-tier policy, so
 * `ListPlaidItems`/`ViewPlaidItem`'s own Filament-framework
 * authorization hooks (`CanAuthorizeResourceAccess`'s
 * `canAccess()`-based mount/hydrate hooks, and `ViewRecord::authorizeAccess()`'s
 * own separate `canView($record)` call) both consult the correct,
 * financial-tier ceiling — never merely hiding the nav item while a
 * direct route hit still resolves. `getEloquentQuery()` layers the
 * same check as defense-in-depth at the query level.
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
        return static::isFirmEntitled();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::isFirmEntitled();
    }

    /**
     * FOUND AND FIXED (Checkpoint 7 authorization review, item 19): the
     * financial-tier ceiling, not `IntegrationAccessPolicyService`'s
     * wider non-financial one — see this class's own docblock.
     */
    public static function isFirmEntitled(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(IntegrationEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(PlaidEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(FinancialIntegrationAccessPolicyService::class)->canView($firmUser->role);
    }

    /**
     * FOUND AND FIXED (Checkpoint 7 authorization review, item 19):
     * overridden rather than left to the default
     * `Gate::authorize('viewAny', FirmIntegration::class)` ->
     * `FirmIntegrationPolicy::viewAny()` chain — that policy is shared
     * with `FirmIntegrationResource` and is wired to the non-financial
     * tier, wrong for this resource. See this class's own docblock.
     */
    public static function canViewAny(): bool
    {
        return static::isFirmEntitled();
    }

    /**
     * FOUND AND FIXED (Checkpoint 7 authorization review, item 19):
     * overridden rather than left to the default
     * `Gate::authorize('view', $record)` -> `FirmIntegrationPolicy::view()`
     * chain, for the same reason as `canViewAny()` above —
     * `ViewRecord::authorizeAccess()` calls THIS method directly (not
     * `canAccess()`), so leaving it unoverridden would silently keep
     * gating `ViewPlaidItem` on the wrong, non-financial tier even after
     * `isFirmEntitled()` above was corrected. Re-confirms firm ownership
     * of the record, mirroring `FirmIntegrationPolicy::view()`'s own
     * defense-in-depth scoping check.
     */
    public static function canView(Model $record): bool
    {
        /** @var FirmIntegration $record */
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || $firmUser->firm_id !== $record->firm_id) {
            return false;
        }

        return static::isFirmEntitled();
    }

    /**
     * FOUND AND FIXED (Checkpoint 7 authorization review, item 19): the
     * provider filter alone previously left this query reachable by any
     * authenticated firm user via a direct route hit whose role failed
     * `isFirmEntitled()` — canAccess()/canView() above are the real
     * boundary, but this is layered on top as defense-in-depth so a
     * gap in the framework's own authorization wiring (or a future
     * caller of this query outside the Filament page lifecycle) can
     * never leak rows to an unentitled/wrong-tier actor.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->whereHas(
            'integrationProvider',
            fn (Builder $query) => $query->where('code', ProviderKey::Plaid->value)
        );

        if (! static::isFirmEntitled()) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
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
