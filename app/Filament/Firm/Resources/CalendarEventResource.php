<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\CalendarEventType;
use App\Filament\Firm\Resources\CalendarEventResource\Pages\CreateCalendarEvent;
use App\Filament\Firm\Resources\CalendarEventResource\Pages\ListCalendarEvents;
use App\Filament\Firm\Resources\CalendarEventResource\Pages\ViewCalendarEvent;
use App\Models\CalendarEvent;
use App\Models\Matter;
use App\Services\TaskCrudAccessPolicyService;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * CalendarEventResource — Mission 5B (5.8). `CalendarEvent`/
 * `CalendarEventService` were a real model + service with zero
 * dedicated Filament resource before this mission (confirmed by
 * exhaustive grep) — `createStandalone()` had no production caller at
 * all (see that service's own docblock). This resource is a
 * deliberately pragmatic v1: an AGENDA/LIST view only, mirroring
 * DeadlineResource's date-heavy table idiom exactly (sortable
 * `starts_at`, a `SelectFilter` on `event_type`, link-out to the
 * linked Matter). No month-grid, no drag-and-drop — this codebase has
 * no existing component for either, and building one is out of this
 * mission's scope.
 *
 * Create is the ONLY mutation surface, and it goes through
 * `CalendarEventService::createStandalone()` (never a bare
 * `CalendarEvent::create()`) — see CreateCalendarEvent's own docblock.
 * `event_type`/`subject_type`/`subject_id` are never form fields:
 * createStandalone() always writes `event_type = Standalone` with no
 * subject (a bare staff-created event, e.g. "client meeting" — that
 * service's own docblock), and the Deadline/Task/MatterActivity event
 * types are exclusively auto-created by DeadlineService/TaskService
 * elsewhere. No Edit page — a calendar event has no update() method on
 * its service today, and none of this mission's verified findings call
 * for one.
 *
 * Authorization: no dedicated CalendarEventPolicy exists yet, so
 * `canAccess()` below is a self-contained, UX-layer role-ceiling check
 * (same shape as FirmIntegrationResource's own `isFirmEntitled()`
 * check) reusing `TaskCrudAccessPolicyService::canView()` unchanged —
 * the same "every active staff role" ceiling Task/Deadline visibility
 * already uses, since a calendar entry is exactly the same class of
 * routine, non-confidential scheduling information. Creation is
 * further gated on `canManageTask()` (the front-desk-inclusive
 * ceiling — CalendarEventService's own docblock explicitly cites
 * "client meeting" as the standalone use case, the same kind of
 * routine scheduling work Receptionist already handles for Tasks).
 * Row-level tenant isolation is enforced by `CalendarEvent`'s own
 * `BelongsToTenant` scope (FORCE RLS), not by this resource.
 */
class CalendarEventResource extends Resource
{
    protected static ?string $model = CalendarEvent::class;

    protected static ?string $slug = 'calendar-events';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $navigationLabel = 'Calendar';

    protected static string|\UnitEnum|null $navigationGroup = 'Tasks & Calendar';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(TaskCrudAccessPolicyService::class)->canView($firmUser->role);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Calendar Event')
                ->columns(2)
                ->schema([
                    TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
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
                    DateTimePicker::make('starts_at')->label('Starts At')->native(false)->required(),
                    DateTimePicker::make('ends_at')->label('Ends At')->native(false)->nullable(),
                    Toggle::make('all_day')->label('All Day'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('event_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'deadline' => 'danger',
                        'reminder' => 'warning',
                        'task' => 'info',
                        'matter_activity' => 'gray',
                        'standalone' => 'primary',
                        default => 'gray',
                    }),
                TextColumn::make('matter.stage')
                    ->label('Matter')
                    ->placeholder('—')
                    ->url(fn (CalendarEvent $record): ?string => $record->matter !== null
                        ? MatterResource::getUrl('view', ['record' => $record->matter])
                        : null),
                TextColumn::make('starts_at')->dateTime()->sortable(),
                TextColumn::make('ends_at')->dateTime()->placeholder('—')->sortable(),
                IconColumn::make('all_day')->label('All Day')->boolean(),
                TextColumn::make('createdBy.name')->label('Created By')->placeholder('—'),
            ])
            ->defaultSort('starts_at', 'asc')
            ->filters([
                SelectFilter::make('event_type')
                    ->options(fn (): array => collect(CalendarEventType::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => str($case->value)->replace('_', ' ')->headline()])
                        ->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCalendarEvents::route('/'),
            'create' => CreateCalendarEvent::route('/create'),
            'view' => ViewCalendarEvent::route('/{record}'),
        ];
    }
}
