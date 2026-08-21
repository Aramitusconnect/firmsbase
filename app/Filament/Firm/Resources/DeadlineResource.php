<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\DeadlineStatus;
use App\Filament\Firm\Resources\DeadlineResource\Actions\CancelDeadlineAction;
use App\Filament\Firm\Resources\DeadlineResource\Actions\CompleteDeadlineAction;
use App\Filament\Firm\Resources\DeadlineResource\Pages\CreateDeadline;
use App\Filament\Firm\Resources\DeadlineResource\Pages\EditDeadline;
use App\Filament\Firm\Resources\DeadlineResource\Pages\ListDeadlines;
use App\Filament\Firm\Resources\DeadlineResource\Pages\ViewDeadline;
use App\Models\Deadline;
use App\Models\Matter;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * DeadlineResource — Firm Feature Manifest §3: "Deadlines — PARTIAL...
 * DeadlineService::create() (auto-creates linked CalendarEvent)."
 * Create is NEVER a bare `Deadline::create()` — see CreateDeadline's
 * own docblock. Edit is deliberately narrow (title/jurisdiction/source/
 * reminder_offsets_days only — see DeadlinePolicy's own docblock for
 * why due_at/deadline_type/matter_id/status are excluded).
 *
 * `status` is never an editable form field — DeadlineService derives
 * Missed from due_at, same discipline as TaskService's Overdue
 * derivation. Status transitions are CompleteDeadlineAction/
 * CancelDeadlineAction, each calling DeadlineService directly.
 *
 * Missed-status honesty note (same reasoning as TaskResource's own
 * docblock): `DeadlineService::refreshMissedStatus()` IS now called
 * daily by `automation:sweep:deadlines` (see bootstrap/app.php's
 * `withSchedule()`), so `status` itself is no longer stale by more
 * than a day. The table below still adds an honest, purely-computed
 * "Due" column colored by comparing `due_at` to `now()` — it never
 * writes back to `status` and never overrides a terminal status — to
 * cover the window between scheduled sweeps.
 */
class DeadlineResource extends Resource
{
    protected static ?string $model = Deadline::class;

    protected static ?string $slug = 'deadlines';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Deadlines';

    protected static string|\UnitEnum|null $navigationGroup = 'Tasks & Calendar';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Deadline')
                ->columns(2)
                ->schema([
                    TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                    TextInput::make('deadline_type')
                        ->label('Deadline Type')
                        ->required()
                        ->maxLength(255)
                        ->helperText('e.g. filing_deadline, discovery_cutoff, statute_of_limitations'),
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
                    DateTimePicker::make('due_at')->label('Due At')->native(false)->required(),
                    TextInput::make('jurisdiction')->maxLength(255),
                    TextInput::make('source')->maxLength(255)->helperText('e.g. court_order, statute, engagement_letter'),
                    TagsInput::make('reminder_offsets_days')
                        ->label('Reminder Offsets (days before due)')
                        ->helperText('e.g. 7, 3, 1 — reminders are calculated only; nothing dispatches them yet.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('deadline_type')->label('Type')->searchable(),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
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
                TextColumn::make('due_at')
                    ->label('Due')
                    ->dateTime()
                    ->color(fn (Deadline $record): string => self::dueColumnColor($record))
                    ->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('due_at', 'asc')
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => collect(DeadlineStatus::cases())->mapWithKeys(fn ($case) => [$case->value => str($case->value)->headline()])->all()),
            ])
            ->recordActions([
                CompleteDeadlineAction::make(),
                CancelDeadlineAction::make(),
            ]);
    }

    /**
     * Purely visual, purely computed — never persisted. Only speaks up
     * for a deadline still Upcoming whose due_at has already passed or
     * is imminent; Due/Missed/Completed/Cancelled already tell the
     * truth (or a human decision already overrode timing) and are left
     * alone.
     */
    private static function dueColumnColor(Deadline $record): string
    {
        if ($record->status !== DeadlineStatus::Upcoming) {
            return 'gray';
        }

        if ($record->due_at->isPast()) {
            return 'danger';
        }

        if ($record->due_at->diffInHours(now(), true) <= 72) {
            return 'warning';
        }

        return 'gray';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeadlines::route('/'),
            'create' => CreateDeadline::route('/create'),
            'view' => ViewDeadline::route('/{record}'),
            'edit' => EditDeadline::route('/{record}/edit'),
        ];
    }
}
