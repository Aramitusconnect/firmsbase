<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\DocumentRequestStatus;
use App\Filament\Firm\Resources\DocumentRequestResource\Pages\CreateDocumentRequest;
use App\Filament\Firm\Resources\DocumentRequestResource\Pages\EditDocumentRequest;
use App\Filament\Firm\Resources\DocumentRequestResource\Pages\ListDocumentRequests;
use App\Filament\Firm\Resources\DocumentRequestResource\Pages\ViewDocumentRequest;
use App\Filament\Firm\Resources\DocumentRequestResource\RelationManagers\ItemsRelationManager;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\Matter;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * DocumentRequestResource — Firm Feature Manifest §5: "Document
 * Requests (client document-collection) — READY... No storage
 * dependency — the safest win in this whole category." This is a
 * document-collection WORKFLOW (asking for and tracking receipt of
 * documents), never a file-upload/storage feature — there is no real
 * file storage or rendering pipeline anywhere in this codebase
 * (cross-cutting finding #6), so this module never adds a file-upload
 * form field or writes to a storage disk anywhere.
 *
 * Creation always goes through `DocumentRequestService::create()`
 * (never a bare `DocumentRequest::create()`) — see CreateDocumentRequest's
 * own docblock; the Repeater below matches that method's own
 * `array<int, array{label:string, is_required?:bool}>` `$items` shape
 * exactly. Edit is deliberately narrow (title/instructions/due_at
 * only) — see DocumentRequestPolicy's own docblock for why `status`/
 * `client_id`/`matter_id` are excluded. Every per-item status
 * transition is a dedicated Action on ItemsRelationManager, never a
 * field on this form.
 *
 * Authorization: standard Laravel Policy (App\Policies\
 * DocumentRequestPolicy). getEloquentQuery() is NOT overridden — like
 * ClientResource/ContactResource, plain BelongsToTenant scoping is the
 * entire list-level boundary (no per-user assignment concept here).
 */
class DocumentRequestResource extends Resource
{
    protected static ?string $model = DocumentRequest::class;

    protected static ?string $slug = 'document-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Document Requests';

    protected static string|\UnitEnum|null $navigationGroup = 'Documents';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Document Request')
                ->columns(2)
                ->schema([
                    Select::make('client_id')
                        ->label('Client')
                        ->options(fn (): array => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all())
                        ->searchable()
                        ->required(),
                    Select::make('matter_id')
                        ->label('Matter')
                        ->options(fn (): array => Matter::query()
                            ->with('client')
                            ->get()
                            ->mapWithKeys(fn (Matter $matter): array => [
                                $matter->id => trim(($matter->client?->display_name ?? 'Matter').' — '."#{$matter->id}"),
                            ])
                            ->all())
                        ->searchable()
                        ->nullable(),
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->default('Document request')
                        ->columnSpanFull(),
                    Textarea::make('instructions')
                        ->rows(3)
                        ->helperText('Shown to whoever is coordinating collection — not sent to the client (no client-facing dispatch exists yet).')
                        ->columnSpanFull(),
                    DateTimePicker::make('due_at')->label('Due At')->native(false),
                ]),
            Section::make('Requested Documents')
                ->description('Each item is tracked individually (Requested → Received → Approved/Waived, etc.) — this never uploads or stores a file, only tracks the request.')
                ->schema([
                    Repeater::make('items')
                        ->label('')
                        ->schema([
                            TextInput::make('label')
                                ->label('Document')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(3),
                            Toggle::make('is_required')
                                ->label('Required')
                                ->default(true)
                                ->columnSpan(1),
                        ])
                        ->columns(4)
                        ->minItems(1)
                        ->required()
                        ->addActionLabel('+ Add Document')
                        ->defaultItems(1),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client.display_name')->label('Client')->searchable()->sortable(),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('title')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'fulfilled' => 'success',
                        'partially_fulfilled' => 'warning',
                        'cancelled' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->state(fn (DocumentRequest $record): int => $record->items()->count()),
                TextColumn::make('due_at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('client_id')
                    ->label('Client')
                    ->options(fn (): array => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all())
                    ->searchable(),
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
                    ->options(fn (): array => collect(DocumentRequestStatus::cases())->mapWithKeys(fn ($case) => [$case->value => str($case->value)->headline()])->all()),
                Filter::make('due_at')
                    ->schema([
                        DateTimePicker::make('due_from')->label('Due from')->native(false),
                        DateTimePicker::make('due_until')->label('Due until')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['due_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('due_at', '>=', $date))
                            ->when($data['due_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('due_at', '<=', $date));
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentRequests::route('/'),
            'create' => CreateDocumentRequest::route('/create'),
            'view' => ViewDocumentRequest::route('/{record}'),
            'edit' => EditDocumentRequest::route('/{record}/edit'),
        ];
    }
}
