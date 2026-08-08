<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Filament\Firm\Resources\MatterResource\Actions\ResolveConflictCheckResultAction;
use App\Services\MatterAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ConflictCheckResultsRelationManager — "Conflict Check Results" tab
 * on ViewMatter, flattening every ConflictCheckResult across all of
 * this matter's ConflictCheckRuns (Matter::conflictCheckResults(), a
 * new HasManyThrough — see that method's own docblock on Matter for
 * why a flattened list beats nesting a table inside each run row).
 * Hosts the per-row "Resolve" action (Confirmed Conflict / Dismissed
 * only, via ConflictCheckService::resolveResult()).
 *
 * Gate matches every other Matter tab: MatterAccessPolicyService::
 * canAccessMatter() is the real per-record boundary. Viewing this tab
 * does NOT require the stricter canResolveConflictResult() ceiling —
 * that is checked per-row, only by ResolveConflictCheckResultAction
 * itself, so a Paralegal who ran the check can still see its results,
 * just not resolve them.
 */
class ConflictCheckResultsRelationManager extends RelationManager
{
    protected static string $relationship = 'conflictCheckResults';

    protected static ?string $title = 'Conflict Check Results';

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
                TextColumn::make('matched_type')->label('Type')->badge(),
                TextColumn::make('matched_value')->label('Matched Value')->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'possible_match' => 'warning',
                        'confirmed_conflict' => 'danger',
                        'dismissed' => 'gray',
                        'clear' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('reviewedBy.name')->label('Reviewed By')->placeholder('—'),
                TextColumn::make('reviewed_at')->dateTime()->placeholder('—'),
                TextColumn::make('review_notes')->label('Notes')->limit(60)->placeholder('—')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([
                ResolveConflictCheckResultAction::make(),
            ])
            ->toolbarActions([]);
    }
}
