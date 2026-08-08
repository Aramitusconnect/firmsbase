<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\TimeEntryStatus;
use App\Filament\Firm\Resources\TimeEntryResource\Actions\ApproveTimeEntryAction;
use App\Filament\Firm\Resources\TimeEntryResource\Actions\RejectTimeEntryAction;
use App\Filament\Firm\Resources\TimeEntryResource\Actions\SubmitTimeEntryAction;
use App\Filament\Firm\Resources\TimeEntryResource\Pages\CreateTimeEntry;
use App\Filament\Firm\Resources\TimeEntryResource\Pages\EditTimeEntry;
use App\Filament\Firm\Resources\TimeEntryResource\Pages\ListTimeEntries;
use App\Filament\Firm\Resources\TimeEntryResource\Pages\ViewTimeEntry;
use App\Models\Client;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\TimeEntry;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * TimeEntryResource — Firm Feature Manifest §6 (Tier1-C): "Time Entries
 * — READY... manual-entry-friendly." Create routes through
 * `TimeEntryApprovalService::createManualEntry()` — NEVER a bare
 * `TimeEntry::create()` — see CreateTimeEntry's own docblock. The
 * "+ Add Time Entry" / "Start Timer" / "Stop Timer" header actions and
 * the Submit/Approve/Reject row actions each call the real domain
 * service directly, matching TaskResource/DeadlineResource's discipline
 * exactly.
 *
 * `status` is NEVER an editable form field on Create or Edit —
 * TimeEntryApprovalService is the exclusive writer of every status
 * transition (submit/approve/reject/markInvoiced). Edit is additionally
 * gated to Draft-only rows by TimeEntryPolicy::update() (TimeEntry has
 * no dedicated "edit" service method, so this mirrors DeadlineResource's
 * own "no update() method exists, so a narrow, invariant-free plain
 * field edit is acceptable" reasoning, plus the extra Draft-only status
 * gate — see TimeEntryPolicy's own docblock for why).
 *
 * Duration is entered as Hours/Minutes on the form (never a raw
 * `seconds` field) and converted to a whole-second integer on submit —
 * TimeEntry's own model docblock: "`seconds` is a whole-second integer
 * column; nothing in this model ever writes a fractional value to it."
 *
 * Timers: `TimeTrackingSession`/`TimeTrackingService` is a REAL,
 * durably-persisted backend timer mechanism (accumulated_seconds is
 * folded into a whole-second integer on every pause/resume/stop —
 * never derived from timestamp subtraction at read time — see that
 * service's own docblock), not a client-side simulation — so "Start
 * Timer"/"Stop Timer" header actions are wired directly to it per this
 * mission's own instruction ("Timers, when backend supports them").
 * Stopping a session creates exactly one Draft TimeEntry from the
 * session's accumulated whole seconds.
 *
 * Tenant-safe relation selectors: `matter_id`/`client_id` options are
 * built from plain Eloquent queries evaluated at page-mount time (a
 * real HTTP request, carrying ambient `app.current_firm_id` context per
 * `FirmPanelProvider`'s `authMiddleware`) — see TaskResource's own
 * docblock for the full reasoning. RLS remains the real boundary
 * against a forged `matter_id`/`client_id` value at submit time.
 *
 * Authorization: standard Laravel Policy mechanism (App\Policies\
 * TimeEntryPolicy), matching TaskResource's approach. Time Entries
 * carry no separate module_catalog entitlement (unlike Expenses) — the
 * Firm Feature Manifest §6 table lists Time Entries as plain READY with
 * no PAID ADD-ON classification, confirmed by no `TimeEntry`/
 * `TimeTrackingSession` reference anywhere in AccountingEntitlementPolicyService
 * or any other *EntitlementPolicyService.
 */
class TimeEntryResource extends Resource
{
    protected static ?string $model = TimeEntry::class;

    protected static ?string $slug = 'time-entries';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Time Entries';

    protected static string|\UnitEnum|null $navigationGroup = 'Time & Expenses';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'description';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Time Entry')
                ->columns(2)
                ->schema([
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
                    TextInput::make('hours')
                        ->label('Hours')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
                    TextInput::make('minutes')
                        ->label('Minutes')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(59)
                        ->default(0)
                        ->required(),
                    DatePicker::make('worked_on')->label('Worked On')->native(false)->default(now())->required(),
                    Toggle::make('is_billable')->label('Billable')->default(true),
                    Textarea::make('description')->rows(3)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('worked_on')->label('Date')->date()->sortable(),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('client.display_name')->label('Client')->placeholder('—'),
                TextColumn::make('user.name')->label('Biller')->placeholder('—'),
                TextColumn::make('seconds')
                    ->label('Duration')
                    ->formatStateUsing(fn (int $state): string => self::formatDuration($state))
                    ->sortable(),
                IconColumn::make('is_billable')->label('Billable')->boolean(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'approved' => 'success',
                        'submitted' => 'info',
                        'invoiced' => 'primary',
                        'rejected' => 'danger',
                        'draft' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('description')->limit(40)->placeholder('—')->searchable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('worked_on', 'desc')
            ->filters([
                SelectFilter::make('matter_id')
                    ->label('Matter')
                    ->options(fn (): array => Matter::query()
                        ->with('client')
                        ->get()
                        ->mapWithKeys(fn (Matter $matter): array => [
                            $matter->id => trim(($matter->client?->display_name ?? 'Matter').' — '."#{$matter->id}"),
                        ])
                        ->all()),
                SelectFilter::make('status')
                    ->options(fn (): array => collect(TimeEntryStatus::cases())->mapWithKeys(fn ($case) => [$case->value => str($case->value)->headline()])->all()),
                TernaryFilter::make('is_billable')->label('Billable'),
                SelectFilter::make('user_id')
                    ->label('Biller')
                    ->options(fn (): array => FirmUser::query()
                        ->with('user')
                        ->where('status', 'active')
                        ->get()
                        ->mapWithKeys(fn (FirmUser $firmUser): array => [$firmUser->user_id => $firmUser->user?->name ?? "User #{$firmUser->user_id}"])
                        ->all()),
                Filter::make('worked_on')
                    ->schema([
                        DatePicker::make('worked_from')->label('Worked from')->native(false),
                        DatePicker::make('worked_until')->label('Worked until')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['worked_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('worked_on', '>=', $date))
                            ->when($data['worked_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('worked_on', '<=', $date));
                    }),
            ])
            ->recordActions([
                SubmitTimeEntryAction::make(),
                ApproveTimeEntryAction::make(),
                RejectTimeEntryAction::make(),
            ]);
    }

    public static function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%dh %02dm', $hours, $minutes);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTimeEntries::route('/'),
            'create' => CreateTimeEntry::route('/create'),
            'view' => ViewTimeEntry::route('/{record}'),
            'edit' => EditTimeEntry::route('/{record}/edit'),
        ];
    }
}
