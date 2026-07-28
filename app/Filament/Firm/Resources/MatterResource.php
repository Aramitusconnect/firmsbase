<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\MatterResource\Pages\ListMatters;
use App\Filament\Firm\Resources\MatterResource\Pages\ViewMatter;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\ActivityRelationManager;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\FinancialEvidenceRelationManager;
use App\Models\Matter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * MatterResource — Checkpoint 4 ("Plaid financial evidence add-on"),
 * Matter resource track (checkpoint4-combined-design.md §4;
 * checkpoint4-design-matter-and-client-portal.md §1). List+View only —
 * deliberately NO Create/Edit page, matching `FirmIntegrationResource`'s
 * own "no ad-hoc Create/Edit form" discipline, here for a different
 * reason: matter opening/status transitions are already the exclusive
 * responsibility of `MatterOpeningService`/`MatterReadinessService`
 * (`Matter`'s own docblock: "status transitions to Open are gated by
 * MatterOpeningService... never set directly") — this resource must not
 * bypass that with a generic Filament form.
 *
 * Authorization: the real per-record boundary is
 * `MatterAccessPolicyService::canAccessMatter()` (pre-existing, built
 * for `AiRetrievalIsolationService` in Phase 15, never previously wired
 * to any UI — reused here, not reimplemented), enforced in
 * `ViewMatter::resolveRecord()`. `canAccess()` below only gates "is this
 * an authenticated firm staff member at all" (the same UX-layer,
 * non-boundary role `FirmIntegrationResource::canAccess()` plays
 * relative to its own real boundary) — row-level filtering is
 * `getEloquentQuery()`'s job (see `Pages\ListMatters`).
 */
class MatterResource extends Resource
{
    protected static ?string $model = Matter::class;

    protected static ?string $slug = 'matters';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $navigationLabel = 'Matters';

    /**
     * Matter has no single human-readable name column ('stage' is a
     * freeform, template-driven string, not a title) — judgment call
     * §1.6.a of the source design doc: 'stage' is the least-bad existing
     * column, avoiding inventing a new title/display_name column on
     * Matter (out of this checkpoint's scope). The list page's real
     * visual identifier is the paired client.display_name + matterType.name
     * columns, not this attribute alone.
     */
    protected static ?string $recordTitleAttribute = 'stage';

    public static function canAccess(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && parent::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return $query->whereRaw('1 = 0');
        }

        if (in_array($firmUser->role, [FirmUserRole::FirmOwner, FirmUserRole::Attorney], true)) {
            // BelongsToTenant's own global scope already narrows this to
            // the acting firm — no further predicate needed for the
            // blanket-access roles.
            return $query;
        }

        return $query->whereHas(
            'matterAssignments',
            fn (Builder $assignments) => $assignments
                ->where('user_id', $firmUser->user_id)
                ->whereNull('removed_at'),
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client.display_name')
                    ->label('Client')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('matterType.name')
                    ->label('Type')
                    ->placeholder('—'),
                TextColumn::make('stage')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'open', 'active' => 'success',
                        'waiting_on_client', 'ready_for_review' => 'warning',
                        'closed', 'archived' => 'gray',
                        'conflict_check_required', 'conflict_review' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('assignedAttorney.name')
                    ->label('Attorney')
                    ->placeholder('—'),
                TextColumn::make('opened_at')->dateTime()->placeholder('—'),
                TextColumn::make('closed_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
            FinancialEvidenceRelationManager::class,
            ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMatters::route('/'),
            'view' => ViewMatter::route('/{record}'),
        ];
    }
}
