<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Resources;

use App\Filament\ClientPortal\Resources\MatterResource\Pages\ListMatters;
use App\Filament\ClientPortal\Resources\MatterResource\Pages\ViewMatter;
use App\Models\ClientPortalUser;
use App\Models\Matter;
use App\Services\ClientPortalMatterAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * MatterResource (Client Portal) — Mission 4 (Client Portal Activation),
 * finding 4.3. Read-only: List + View only, deliberately no Create/Edit
 * page — a client cannot create or edit a matter, mirroring the Firm
 * panel's own `MatterResource` "no ad-hoc Create/Edit form" discipline
 * for the identical underlying reason (matter lifecycle is exclusively
 * `MatterOpeningService`/`MatterReadinessService`'s responsibility, not
 * any UI form).
 *
 * Scoping is EXCLUSIVELY through
 * `ClientPortalMatterAccessPolicyService::grantedMatterIds()` — never
 * inferred from `Matter.client_id` alone (that is precisely the
 * authorization shortcut the design doc explicitly rejects; see
 * `ClientPortalMatterGrant`'s own docblock). `getEloquentQuery()` here
 * is the list-level UX filter only; `ViewMatter::resolveRecord()` is
 * the real per-record boundary, re-checking
 * `canAccessMatter()` directly — the identical "list is UX filter,
 * resolve step is the boundary" split
 * `App\Filament\Firm\Resources\MatterResource`/`ViewMatter` already
 * draws for the Firm panel, and that
 * `ClientPortalMatterAccessPolicyService`'s own docblock documents.
 *
 * Field allowlist (enforced in the table/infolist, not here): status,
 * primaryPracticeArea name, assignedAttorney display name, opened_at/
 * closed_at only. Never conflictCheckRuns, matterAssignments (full
 * list), intakeSubmissions, readinessScore, timeEntries, expenses,
 * matterBudgets, leverageRecommendations, or any internal note/strategy
 * field.
 */
class MatterResource extends Resource
{
    protected static ?string $model = Matter::class;

    protected static ?string $slug = 'matters';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $navigationLabel = 'My Matters';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'stage';

    public static function canAccess(): bool
    {
        return Auth::guard('client')->check() && parent::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        if ($portalUser === null) {
            return $query->whereRaw('1 = 0');
        }

        $grantedMatterIds = app(ClientPortalMatterAccessPolicyService::class)->grantedMatterIds($portalUser);

        if ($grantedMatterIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', $grantedMatterIds);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('primaryPracticeArea.name')->label('Practice Area')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'open', 'active' => 'success',
                        'waiting_on_client', 'ready_for_review' => 'warning',
                        'closed', 'archived' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('assignedAttorney.name')->label('Attorney')->placeholder('—'),
                TextColumn::make('opened_at')->dateTime()->placeholder('—'),
                TextColumn::make('closed_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('opened_at', 'desc')
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No matters shared with you yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMatters::route('/'),
            'view' => ViewMatter::route('/{record}'),
        ];
    }
}
