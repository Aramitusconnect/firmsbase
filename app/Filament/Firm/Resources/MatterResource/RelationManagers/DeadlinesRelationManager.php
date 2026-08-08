<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Filament\Firm\Resources\DeadlineResource;
use App\Models\Deadline;
use App\Services\MatterAccessPolicyService;
use App\Services\TaskCrudAccessPolicyService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * DeadlinesRelationManager — Tier1-G, "Deadlines" tab on ViewMatter,
 * listing this matter's Deadline rows (`Matter::deadlines()`, a real,
 * already-defined HasMany).
 *
 * Deliberately read-only with a "View" link-out to DeadlineResource's
 * own ViewRecord page (which hosts the real Complete/Cancel row
 * actions) — never duplicating DeadlineService mutation here.
 *
 * Gate combines MatterAccessPolicyService::canAccessMatter() with
 * TaskCrudAccessPolicyService::canView() — that service's own docblock
 * documents its VIEW ceiling as covering both Task AND Deadline
 * viewing (same role list), so this deliberately does not introduce a
 * second, parallel "can view deadline" check.
 */
class DeadlinesRelationManager extends RelationManager
{
    protected static string $relationship = 'deadlines';

    protected static ?string $title = 'Deadlines';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        if (! app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $ownerRecord)) {
            return false;
        }

        return app(TaskCrudAccessPolicyService::class)->canView($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('deadline_type')->label('Type')->searchable(),
                TextColumn::make('jurisdiction')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'completed' => 'success',
                        'due' => 'warning',
                        'missed' => 'danger',
                        'cancelled' => 'gray',
                        'upcoming' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('due_at')->label('Due')->dateTime()->sortable(),
            ])
            ->defaultSort('due_at', 'asc')
            ->headerActions([])
            ->recordActions([
                Action::make('viewDeadline')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Deadline $record): string => DeadlineResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
