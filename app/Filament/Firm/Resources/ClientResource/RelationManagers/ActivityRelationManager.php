<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\RelationManagers;

use App\Models\Client;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\ClientCrmAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;

/**
 * ActivityRelationManager — Tier1-G, "Activity" tab on
 * ClientResource\ViewClient. Reuses the exact same structural approach
 * as MatterResource\RelationManagers\ActivityRelationManager (see that
 * class's own docblock in full): `TimelineEvent` has no `client()`
 * relationship and none is added solely for this tab — getRelationship()
 * below hand-constructs a `HasMany` the identical way, scoped to
 * `subject_type = Client::class` instead of `Matter::class`.
 *
 * Same honest disclosure as the Matter tab, confirmed independently
 * here: no `client.*`-prefixed event type is emitted anywhere in this
 * codebase today (direct source read — no `TimelineEventRecorder::
 * record(...)` call site passes a `Client` subject with a `client.*`
 * event type). This tab is registered now because it structurally
 * works today with zero backend change, exactly like the Matter tab —
 * it starts showing rows only once/if a later phase emits `client.*`
 * events through `TimelineEventRecorder::record($client->firm,
 * 'client.xxx', $client, ...)`. It is NOT a promise that client
 * activity is tracked today.
 *
 * Gate mirrors this resource's sibling tabs (ContactsRelationManager/
 * CommunicationConsentsRelationManager/DocumentRequestsRelationManager):
 * ClientCrmAccessPolicyService::canView() plus the same firm-match
 * defense-in-depth check.
 */
class ActivityRelationManager extends RelationManager
{
    // Same reasoning as MatterResource's own ActivityRelationManager:
    // getRelationship() is overridden below with no real named
    // relationship to derive a title from, so $title must be declared
    // explicitly or Filament's tab-title computation throws.
    protected static ?string $title = 'Activity';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof Client || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(ClientCrmAccessPolicyService::class)->canView($firmUser->role);
    }

    public function getRelationship(): Relation|Builder
    {
        return new HasMany(
            TimelineEvent::query()->where('subject_type', Client::class),
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
            ->emptyStateHeading('No activity recorded yet for this client.')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
