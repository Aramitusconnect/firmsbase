<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Filament\Firm\Resources\MatterResource\Actions\RunConflictCheckAction;
use App\Services\MatterAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ConflictChecksRelationManager — "Conflict Checks" tab on
 * ViewMatter, listing this matter's ConflictCheckRun rows (Matter::
 * conflictCheckRuns(), a real, already-defined HasMany) and hosting
 * the "Run Conflict Check" header action. Results themselves live on
 * the separate ConflictCheckResultsRelationManager tab (flattened
 * across every run) rather than nested inside each row here — Filament
 * relation managers don't support a clean nested-table-inside-a-row
 * UI, and a flat, resolve-able results list is more useful anyway.
 *
 * Gate matches every other Matter tab: MatterAccessPolicyService::
 * canAccessMatter() is the real per-record boundary.
 */
class ConflictChecksRelationManager extends RelationManager
{
    protected static string $relationship = 'conflictCheckRuns';

    protected static ?string $title = 'Conflict Checks';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'completed' => 'success',
                        'running', 'pending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('scope')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                TextColumn::make('result_count')->label('Results'),
                TextColumn::make('requestedBy.name')->label('Requested By')->placeholder('—'),
                TextColumn::make('completed_at')->dateTime()->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                RunConflictCheckAction::make(),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
