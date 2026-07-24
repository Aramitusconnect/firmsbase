<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ListFirmIntegrations;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ViewFirmIntegration;
use App\Filament\Firm\Resources\FirmIntegrationResource\RelationManagers\ConflictsRelationManager;
use App\Filament\Firm\Resources\FirmIntegrationResource\RelationManagers\FailedItemsRelationManager;
use App\Filament\Firm\Resources\FirmIntegrationResource\RelationManagers\SyncRunsRelationManager;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Services\IntegrationEntitlementPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * FirmIntegrationResource — Checkpoint 10 (frozen-design-post-security-
 * review.md §12; agent-10h-architecture-security-review.md §11.1-§11.4).
 * The first Filament Resource in this codebase's `App\Filament\Firm\*`
 * namespace. List/View pages only — deliberately NO Create/Edit pages
 * (§11.1's "Action-based, never Form-backed Create/Edit page" ruling):
 * "Connect" is a redirect-initiation Action with no user-entered record
 * fields of its own, and "configure" is narrowly rename +
 * webhook-routing toggles, neither of which needs a generic
 * model-bound Form schema that could ever accidentally reference
 * credential fields.
 *
 * `recordTitleAttribute` names ONLY `display_label` — a confirmed SAFE
 * column (10D §1.1) — never a HIDDEN-ONLY/NEVER column, per frozen
 * design §11's global-search discipline requirement.
 *
 * `FirmIntegrationPolicy` (unmodified — §11.3 ruling) already gates
 * `viewAny`/`view` via Laravel's standard policy mechanism, which
 * Filament's own `canAccess()`/`canViewAny()` defaults already consult
 * automatically (`HasAuthorization::canAccess()` -> `canViewAny()` ->
 * Gate::authorize('viewAny', FirmIntegration::class)`). That covers
 * ROLE authority. Entitlement is a SEPARATE, UX-layer, non-throwing
 * check this class layers on top (frozen design §4's "UX-layer,
 * non-boundary" requirement: hide the feature ENTIRELY for a
 * disentitled firm, never merely grey it out) — canAccess() below
 * combines both; shouldRegisterNavigation() mirrors it for the nav
 * item itself. Neither substitutes for the REAL boundary, which is
 * every mutating service method's own assertEnabled()/assertCan*()
 * calls, re-checked unconditionally inside each action's own closure.
 */
class FirmIntegrationResource extends Resource
{
    protected static ?string $model = FirmIntegration::class;

    protected static ?string $slug = 'firm-integrations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $recordTitleAttribute = 'display_label';

    public static function canAccess(): bool
    {
        return parent::canAccess() && static::isFirmEntitled();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::isFirmEntitled();
    }

    private static function isFirmEntitled(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(IntegrationEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(IntegrationAccessPolicyService::class)->canView($firmUser->role);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_label')
                    ->label('Connection')
                    ->searchable()
                    ->default('Untitled connection'),
                TextColumn::make('integrationProvider.display_name')
                    ->label('Provider'),
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
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            SyncRunsRelationManager::class,
            FailedItemsRelationManager::class,
            ConflictsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFirmIntegrations::route('/'),
            'view' => ViewFirmIntegration::route('/{record}'),
        ];
    }
}
