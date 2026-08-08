<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Firm\Resources\TaskResource\Actions\AddTaskDependencyAction;
use App\Filament\Firm\Resources\TaskResource\Actions\CancelTaskAction;
use App\Filament\Firm\Resources\TaskResource\Actions\CompleteTaskAction;
use App\Filament\Firm\Resources\TaskResource\Actions\StartTaskAction;
use App\Filament\Firm\Resources\TaskResource\Pages\CreateTask;
use App\Filament\Firm\Resources\TaskResource\Pages\EditTask;
use App\Filament\Firm\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Firm\Resources\TaskResource\Pages\ViewTask;
use App\Models\Client;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\Task;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * TaskResource — Firm Feature Manifest §3: "Tasks — READY... Simple,
 * manual-entry-friendly." Unlike Client/FirmLead, Task carries no
 * creation restriction in the manifest, so Create/Edit both exist as
 * ordinary Filament pages (matching ContactResource's discipline, not
 * ClientResource's) — see CreateTask's own docblock for why this
 * resource nonetheless routes through TaskService::create() rather
 * than a bare `Task::create()` (consistency with the service-layer
 * discipline this whole mission requires, and it establishes
 * `status = Open` and `created_by` explicitly rather than leaving them
 * to form defaults).
 *
 * `status` is deliberately NEVER an editable form field on Create or
 * Edit — TaskService derives Overdue from due_at ("not manually
 * trusted" — Task's own model docblock) and TaskDependencyService is
 * the only place Blocked is set/cleared. All status transitions are
 * separate row Actions (StartTaskAction/CompleteTaskAction/
 * CancelTaskAction/AddTaskDependencyAction), each calling the real
 * service method directly — see those classes' own docblocks.
 *
 * Overdue/Missed honesty note (Firm Feature Manifest §3 / this
 * mission's own instruction): `TaskService::refreshOverdueStatus()` is
 * never called by any scheduler, so a Task's stored `status` can lag
 * reality (e.g. still show `Open` after its due_at has passed). Rather
 * than silently trusting the stale stored status, the table below adds
 * an honest, purely-computed "Due" column (see `dueColumnColor()`)
 * that compares `due_at` against `now()` at render time — it never
 * writes back to the `status` column (that remains
 * TaskService::refreshOverdueStatus()'s exclusive job once a scheduler
 * exists) and never contradicts a terminal status (Completed/
 * Cancelled), it only tells the truth about an open/in-progress task
 * whose stored status has not caught up yet.
 *
 * Tenant-safe relation selectors: `matter_id`/`client_id`/`assigned_to`
 * options are all built from plain Eloquent queries evaluated at
 * page-mount time (a real HTTP request, which carries ambient
 * `app.current_firm_id` context per `FirmPanelProvider`'s
 * `authMiddleware` — see ContactResource's own docblock for the full
 * reasoning) — `Matter`/`Client`/`FirmUser` are all BelongsToTenant +
 * FORCE RLS, so these lists can never surface another firm's rows
 * regardless of what a malicious client submits, and RLS (not this
 * option list) remains the real boundary against a forged `matter_id`/
 * `client_id`/`assigned_to` value at submit time too.
 *
 * Authorization: standard Laravel Policy mechanism (App\Policies\
 * TaskPolicy), matching ContactResource's approach.
 */
class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static ?string $slug = 'tasks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?string $navigationLabel = 'Tasks';

    protected static string|\UnitEnum|null $navigationGroup = 'Tasks & Calendar';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Task')
                ->columns(2)
                ->schema([
                    TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                    Textarea::make('description')->rows(3)->columnSpanFull(),
                    Select::make('matter_id')
                        ->label('Matter')
                        ->options(fn (): array => Matter::query()
                            ->with(['client', 'matterType'])
                            ->get()
                            ->mapWithKeys(fn (Matter $matter): array => [
                                $matter->id => trim(($matter->client?->display_name ?? 'Matter').' — '.($matter->matterType?->name ?? $matter->stage ?? "#{$matter->id}")),
                            ])
                            ->all())
                        ->searchable()
                        ->nullable(),
                    Select::make('client_id')
                        ->label('Client')
                        ->options(fn (): array => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all())
                        ->searchable()
                        ->nullable(),
                    Select::make('assigned_to')
                        ->label('Assigned To')
                        ->options(fn (): array => FirmUser::query()
                            ->with('user')
                            ->where('status', 'active')
                            ->get()
                            ->mapWithKeys(fn (FirmUser $firmUser): array => [$firmUser->user_id => $firmUser->user?->name ?? "User #{$firmUser->user_id}"])
                            ->all())
                        ->searchable()
                        ->nullable(),
                    Select::make('priority')
                        ->options(fn (): array => collect(TaskPriority::cases())->mapWithKeys(fn ($case) => [$case->value => str($case->value)->headline()])->all())
                        ->default(TaskPriority::Normal->value)
                        ->required(),
                    DateTimePicker::make('due_at')->label('Due At')->native(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('client.display_name')->label('Client')->placeholder('—'),
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
                TextColumn::make('due_at')
                    ->label('Due')
                    ->dateTime()
                    ->placeholder('—')
                    ->color(fn (Task $record): string => self::dueColumnColor($record))
                    ->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('due_at', 'asc')
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => collect(TaskStatus::cases())->mapWithKeys(fn ($case) => [$case->value => str($case->value)->replace('_', ' ')->headline()])->all()),
                SelectFilter::make('priority')
                    ->options(fn (): array => collect(TaskPriority::cases())->mapWithKeys(fn ($case) => [$case->value => str($case->value)->headline()])->all()),
            ])
            ->recordActions([
                StartTaskAction::make(),
                CompleteTaskAction::make(),
                CancelTaskAction::make(),
                AddTaskDependencyAction::make(),
            ]);
    }

    /**
     * Purely visual, purely computed — never persisted. Only speaks up
     * for a task that is still open-for-work (see Task::isOpenForWork())
     * whose due_at has already passed or is imminent; a terminal status
     * (Completed/Cancelled) or an already-Blocked/Overdue status is left
     * alone (those already tell the truth, or a human decision already
     * overrode timing).
     */
    private static function dueColumnColor(Task $record): string
    {
        if ($record->due_at === null) {
            return 'gray';
        }

        if (! in_array($record->status, [TaskStatus::Open, TaskStatus::InProgress], true)) {
            return 'gray';
        }

        if ($record->due_at->isPast()) {
            return 'danger';
        }

        if ($record->due_at->diffInHours(now(), true) <= 48) {
            return 'warning';
        }

        return 'gray';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'create' => CreateTask::route('/create'),
            'view' => ViewTask::route('/{record}'),
            'edit' => EditTask::route('/{record}/edit'),
        ];
    }
}
