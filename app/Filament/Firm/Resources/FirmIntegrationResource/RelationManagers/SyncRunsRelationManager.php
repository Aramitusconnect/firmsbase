<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmIntegrationResource\RelationManagers;

use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\TriggerManualSyncAction;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Services\IntegrationEntitlementPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;

/**
 * SyncRunsRelationManager — Checkpoint 10 (frozen-design-post-security-
 * review.md §12). Read-only history of `integration_sync_runs` rows for
 * this connection, plus the "Request Manual Sync" header action.
 *
 * `FirmIntegration` (a file this checkpoint may not modify) has no
 * `syncRuns()` Eloquent relationship defined on it. getRelationship()
 * below returns a genuine, manually-constructed `HasMany` `Relation`
 * instance instead — NOT a bare `Builder` — because
 * `Filament\Tables\Table::getRelationshipQuery()` calls
 * `$relationship->getQuery()` and requires that call to return an
 * `Illuminate\Database\Eloquent\Builder`. A real `Relation::getQuery()`
 * does exactly that; a bare `Eloquent\Builder::getQuery()` returns the
 * underlying `Illuminate\Database\Query\Builder` instead (an entirely
 * different, non-Eloquent object) — a confirmed, reproduced
 * `TypeError` (`Filament\Tables\Table::getRelationshipQuery(): Return
 * value must be of type ?Illuminate\Database\Eloquent\Builder,
 * Illuminate\Database\Query\Builder returned`). Constructing the
 * `HasMany` directly (`new HasMany($query, $parent, $foreignKey,
 * $localKey)`) needs no `syncRuns()` method on the model at all — the
 * relation is fully self-contained here, and its constructor already
 * applies the `firm_integration_id = $ownerRecord->id` constraint via
 * `HasOneOrMany::addConstraints()`, so no additional `->where()` is
 * needed.
 *
 * No create/edit/delete actions — `integration_sync_runs` is written
 * exclusively by SyncRunService/PullSyncJob/PushSyncJob, never through
 * this UI directly.
 */
class SyncRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'syncRuns';

    /**
     * Filament's default `canViewForRecord()` is `public static` and
     * calls `$ownerRecord->{static::getRelationshipName()}()` directly
     * on the model to discover which class to authorize against — it
     * cannot use this class's own (instance-level) `getRelationship()`
     * override below. Since `FirmIntegration` has no `syncRuns()`
     * relationship method (and may not gain one — see this class's own
     * `getRelationship()` docblock), that default logic throws a
     * `BadMethodCallException` before ever reaching the table. This
     * override replaces it with the same firm-membership + role check
     * `FirmIntegrationPolicy::view()`/`FirmIntegrationResource::
     * isFirmEntitled()` already use for the owning record and resource,
     * so a tab never renders — nor a `BadMethodCallException` leaks —
     * for a user who could not otherwise view this connection.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(IntegrationEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(IntegrationAccessPolicyService::class)->canView($firmUser->role);
    }

    public function getRelationship(): Relation|Builder
    {
        return new HasMany(
            IntegrationSyncRun::query(),
            $this->getOwnerRecord(),
            'firm_integration_id',
            'id',
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('resource_type')
            ->headerActions([
                TriggerManualSyncAction::make(),
            ])
            ->columns([
                TextColumn::make('id')->label('Run #')->sortable(),
                TextColumn::make('resource_type')->badge(),
                TextColumn::make('sync_direction')
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_object($state) ? $state->value : $state),
                TextColumn::make('run_type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_object($state) ? $state->value : $state),
                TextColumn::make('trigger_source')
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_object($state) ? $state->value : $state),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match (is_object($state) ? $state->value : $state) {
                        'succeeded' => 'success',
                        'partial_failure' => 'warning',
                        'failed' => 'danger',
                        'cancelled' => 'gray',
                        'running' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('items_total')->label('Total')->alignEnd(),
                TextColumn::make('items_succeeded')->label('OK')->alignEnd(),
                TextColumn::make('items_failed')->label('Failed')->alignEnd(),
                TextColumn::make('items_skipped')->label('Skipped')->alignEnd(),
                TextColumn::make('error_summary')->label('Error')->limit(60)->toggleable(),
                TextColumn::make('started_at')->dateTime(),
                TextColumn::make('finished_at')->dateTime(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([])
            ->toolbarActions([]);
    }
}
