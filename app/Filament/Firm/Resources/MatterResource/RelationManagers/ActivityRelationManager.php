<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Models\Matter;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\MatterAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;

/**
 * ActivityRelationManager — Checkpoint 4 ("Plaid financial evidence
 * add-on"), "Activity" tab. `TimelineEvent` has no `matter()`
 * relationship and should not gain one solely for this tab (the
 * codebase's established "don't add relationship methods just for a
 * RelationManager" precedent — see SyncRunsRelationManager's own
 * docblock) — getRelationship() below hand-constructs a `HasMany`
 * exactly the same way.
 *
 * Read-only, no new event types are emitted by this checkpoint (Matter
 * mutation services are untouched) — this tab starts showing rows once/
 * if a later phase emits `matter.*`-prefixed events through
 * `TimelineEventRecorder::record($matter->firm, 'matter.xxx', $matter, ...)`.
 * It is registered now ("where supported") because it structurally
 * works today with zero backend change, even though it is empty until
 * write-side events exist.
 */
class ActivityRelationManager extends RelationManager
{
    // Unlike DocumentsRelationManager (which sets $relationship and lets
    // Filament auto-derive both the relationship AND the tab title from
    // it), this class overrides getRelationship() directly below (no
    // real matter() relationship exists to name here) -- which leaves
    // Filament with no string to derive a title from. Without this
    // explicit $title, Filament's tab-title computation throws ("Class
    // name must be a valid object or a string"), crashing ViewMatter
    // for every user -- found by Checkpoint 4's own test-writing pass.
    protected static ?string $title = 'Activity';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $ownerRecord);
    }

    public function getRelationship(): Relation|Builder
    {
        return new HasMany(
            TimelineEvent::query()->where('subject_type', Matter::class),
            $this->getOwnerRecord(),
            'subject_id',
            'id',
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event_type'),
                TextColumn::make('actor_id')
                    ->label('Actor')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : User::query()->find($state)?->name)
                    ->placeholder('—'),
                TextColumn::make('occurred_at')->dateTime()->sortable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->emptyStateHeading('No activity recorded yet for this matter.')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
