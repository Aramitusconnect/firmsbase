<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Filament\Firm\Resources\TaskResource;
use App\Models\Task;
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
 * TasksRelationManager — Tier1-G, "Tasks" tab on ViewMatter, listing
 * this matter's Task rows (`Matter::tasks()`, a real, already-defined
 * HasMany — see DocumentsRelationManager's own docblock on this same
 * resource for the identical "already-defined HasMany" shape).
 *
 * Deliberately read-only with a "View" link-out to TaskResource's own
 * ViewRecord page (which hosts the real Start/Complete/Cancel/
 * AddDependency row actions) — mirrors DocumentRequestsRelationManager's
 * pattern, never duplicating TaskService/TaskDependencyService mutation
 * here.
 *
 * Gate combines MatterAccessPolicyService::canAccessMatter() with
 * TaskCrudAccessPolicyService::canView() — the same role ceiling
 * TaskResource itself is authorized by.
 */
class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Tasks';

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
                TextColumn::make('assignedTo.name')->label('Assigned To')->placeholder('—'),
                TextColumn::make('priority')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'normal' => 'info',
                        'low' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'completed' => 'success',
                        'in_progress' => 'info',
                        'blocked' => 'danger',
                        'overdue' => 'warning',
                        'cancelled' => 'gray',
                        'open' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('due_at')->label('Due')->dateTime()->placeholder('—')->sortable(),
            ])
            ->defaultSort('due_at', 'asc')
            ->headerActions([])
            ->recordActions([
                Action::make('viewTask')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
