<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Filament\Firm\Resources\ClientResource\Pages\EditClient;
use App\Filament\Firm\Resources\ClientResource\Pages\ListClients;
use App\Filament\Firm\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\ActivityRelationManager;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\CommunicationConsentsRelationManager;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\DocumentRequestsRelationManager;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\ExpensesRelationManager;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\MattersRelationManager;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\TimeEntriesRelationManager;
use App\Filament\Firm\Resources\PaymentResource\Actions\RecordClientPaymentAction;
use App\Models\Client;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * ClientResource — Firm Feature Manifest §1: Client creation is
 * EXCLUSIVELY via LeadConversionService::convert() ("a lead must not
 * silently become a client" — explicit project rule). This is why
 * there is deliberately NO 'create' page here (matching
 * FirmIntegrationResource's/MatterResource's own "no ad-hoc Create
 * page" discipline, here for the strongest possible reason: a generic
 * Filament CreateRecord page bound directly to the Client model would
 * call `Client::create()`, which is exactly the call this whole
 * mission is designed to prevent anywhere outside
 * LeadConversionService). The product-required "+ Add Client" primary
 * action instead lives on ListClients as a custom header Action
 * (AddClientAction) that creates a FirmLead then immediately converts
 * it — see that class's own docblock.
 *
 * EditClient is intentionally narrow: the Firm Feature Manifest §1
 * confirms "no ClientService::update()/archive exists... a genuine
 * backend gap, not just UI." Decision made here (documented, not
 * silently assumed): direct Eloquent update IS acceptable for a
 * narrow, explicit allowlist of genuinely safe profile fields
 * (display_name, legal_name, email, phone, preferred_language,
 * preferred_timezone) — none of these carry any invariant a service
 * layer would need to protect (unlike portal_status/portal_invitation_*,
 * which prepare a not-yet-built client-portal invitation flow and are
 * deliberately NOT exposed here, or communication_preferences_id/
 * created_by, which are system-managed). A dedicated ClientService::
 * update() remains the right long-term home for this if the editable
 * field set ever grows to include anything with real invariants.
 *
 * Authorization: standard Laravel Policy mechanism (App\Policies\
 * ClientPolicy), matching FirmIntegrationResource's approach.
 * getEloquentQuery() is NOT overridden — Client has no per-user
 * assignment concept the way Matter does (MatterAccessPolicyService's
 * extra assignment-based narrowing has no CRM analog here), so plain
 * BelongsToTenant scoping (already applied to every Eloquent query
 * automatically) is the entire list-level boundary, same as
 * FirmIntegrationResource.
 */
class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static ?string $slug = 'clients';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Clients';

    protected static string|\UnitEnum|null $navigationGroup = 'Clients & Matters';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Client Profile')
                ->columns(2)
                ->schema([
                    TextInput::make('display_name')->label('Client Name')->required()->maxLength(255),
                    TextInput::make('legal_name')->label('Legal Name')->maxLength(255),
                    TextInput::make('email')->email()->maxLength(255),
                    TextInput::make('phone')->maxLength(255),
                    TextInput::make('preferred_language')->label('Preferred Language')->maxLength(255),
                    TextInput::make('preferred_timezone')->label('Preferred Timezone')->maxLength(255),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')->label('Name')->searchable()->sortable(),
                TextColumn::make('legal_name')->searchable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('email')->searchable()->placeholder('—'),
                TextColumn::make('phone')->searchable()->placeholder('—'),
                TextColumn::make('portal_status')
                    ->label('Portal')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'active' => 'success',
                        'invited' => 'warning',
                        'disabled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                // Firm Feature Manifest §6, cross-cutting finding #11:
                // "Record Payment" reachable from Client context, not
                // just PaymentResource's own list page. Shares its
                // entire submission path with PaymentResource's header
                // action via RecordsManualPayment — see that action's
                // own docblock.
                RecordClientPaymentAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MattersRelationManager::class,
            ContactsRelationManager::class,
            CommunicationConsentsRelationManager::class,
            DocumentRequestsRelationManager::class,
            TimeEntriesRelationManager::class,
            ExpensesRelationManager::class,
            PaymentsRelationManager::class,
            ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
