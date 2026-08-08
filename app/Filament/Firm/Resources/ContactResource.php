<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Filament\Firm\Resources\ContactResource\Pages\CreateContact;
use App\Filament\Firm\Resources\ContactResource\Pages\EditContact;
use App\Filament\Firm\Resources\ContactResource\Pages\ListContacts;
use App\Filament\Firm\Resources\ContactResource\Pages\ViewContact;
use App\Models\Client;
use App\Models\Contact;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * ContactResource — Firm Feature Manifest §1: "Contact — direct CRUD
 * safe... No creation restriction — safe for a normal Filament
 * resource." Unlike ClientResource, this is a genuinely ordinary
 * List/Create/Edit/View resource — no service-mediated creation path
 * exists or is required.
 *
 * Authorization: standard Laravel Policy mechanism (App\Policies\
 * ContactPolicy, auto-discovered by Filament's HasAuthorization trait
 * — see FirmIntegrationResource's own docblock for how canAccess()/
 * canCreate()/canEdit() resolve through Gate::authorize() by
 * convention), matching FirmIntegrationResource's approach rather than
 * MatterResource's ad-hoc canAccess()/getEloquentQuery() override
 * (Contact has no per-user assignment concept the way Matter does, so
 * plain BelongsToTenant scoping is the entire list-level boundary —
 * no getEloquentQuery() override is needed here).
 *
 * `client_id` uses ->relationship()->preload() rather than a lazy
 * AJAX-searched select — see CreateContact/EditContact's own docblock
 * for why: a searched-on-demand select would query via Filament's
 * Livewire `livewire/update` endpoint, which (see
 * WrapsRecordMutationInFirmContext's docblock) carries no ambient
 * tenant context; preloading at page-mount time (a real HTTP page
 * load, which DOES carry ambient context) avoids that gap entirely for
 * a per-firm client list of this size.
 */
class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static ?string $slug = 'contacts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Contacts';

    protected static string|\UnitEnum|null $navigationGroup = 'Clients & Matters';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contact')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('company')->maxLength(255),
                    TextInput::make('email')->email()->maxLength(255),
                    TextInput::make('phone')->maxLength(255),
                    TextInput::make('role')
                        ->maxLength(255)
                        ->helperText('e.g. Opposing Counsel, Witness, Referral Source'),
                    Select::make('client_id')
                        ->label('Linked Client')
                        // Deliberately a plain options() lookup, not
                        // ->relationship('client', ...): the latter
                        // validates the submitted value against a fresh
                        // relationship-scoped query AT SUBMIT TIME
                        // (Livewire's ->call('create')/->call('save'),
                        // which in a real browser session runs via the
                        // no-ambient-context `livewire/update` endpoint —
                        // see WrapsRecordMutationInFirmContext's
                        // docblock), which failed validation in testing
                        // for exactly that reason. A plain options() list
                        // (loaded once at page-mount time, a real HTTP
                        // request with ambient context) binds directly to
                        // the client_id column with no extra query at
                        // submit time.
                        ->options(fn (): array => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all())
                        ->searchable()
                        ->nullable(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('company')->searchable()->placeholder('—'),
                TextColumn::make('email')->searchable()->placeholder('—'),
                TextColumn::make('phone')->searchable()->placeholder('—'),
                TextColumn::make('role')->placeholder('—'),
                TextColumn::make('client.display_name')->label('Linked Client')->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
            'create' => CreateContact::route('/create'),
            'view' => ViewContact::route('/{record}'),
            'edit' => EditContact::route('/{record}/edit'),
        ];
    }
}
