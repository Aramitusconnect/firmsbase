<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\FirmLeadStatus;
use App\Filament\Firm\Resources\FirmLeadResource\Actions\ConvertLeadToClientAction;
use App\Filament\Firm\Resources\FirmLeadResource\Actions\MarkLeadContactedAction;
use App\Filament\Firm\Resources\FirmLeadResource\Actions\MarkLeadLostAction;
use App\Filament\Firm\Resources\FirmLeadResource\Actions\ScheduleConsultationAction;
use App\Filament\Firm\Resources\FirmLeadResource\Pages\CreateFirmLead;
use App\Filament\Firm\Resources\FirmLeadResource\Pages\EditFirmLead;
use App\Filament\Firm\Resources\FirmLeadResource\Pages\ListFirmLeads;
use App\Filament\Firm\Resources\FirmLeadResource\Pages\ViewFirmLead;
use App\Filament\Firm\Resources\FirmLeadResource\RelationManagers\ConsultationsRelationManager;
use App\Models\FirmLead;
use App\Models\LeadSource;
use App\Models\PracticeArea;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * FirmLeadResource — Firm Feature Manifest §1: "Firm lead creation
 * unrestricted; status = Converted transition MUST go through
 * LeadConversionService::convert(), never a hand-set enum field on a
 * form" (this mission's rule #4). Enforced structurally, not by
 * convention alone: `status`/`converted_client_id`/`converted_at` are
 * simply never present as fields on form() below — no Create/Edit
 * submission on this Resource can ever set them, regardless of role.
 * The ONLY path to Converted is ConvertLeadToClientAction, a distinct
 * row action calling LeadConversionService::convert() directly (never
 * routed through this Resource's own save/update lifecycle).
 *
 * EditFirmLead additionally blocks editing entirely once a lead is
 * Converted (FirmLeadPolicy::update() returns false for a converted
 * lead) — a converted lead's remaining fields (name/email/phone/etc.)
 * are historical intake data at that point, not something that should
 * be revised after the fact.
 *
 * Authorization: standard Laravel Policy mechanism (App\Policies\
 * FirmLeadPolicy), matching FirmIntegrationResource's approach.
 */
class FirmLeadResource extends Resource
{
    protected static ?string $model = FirmLead::class;

    protected static ?string $slug = 'firm-leads';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $navigationLabel = 'Leads';

    protected static string|\UnitEnum|null $navigationGroup = 'Clients & Matters';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lead')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('email')->email()->maxLength(255),
                    TextInput::make('phone')->maxLength(255),
                    Select::make('lead_source_id')
                        ->label('Lead Source')
                        ->options(function (): array {
                            $firmUser = Auth::user()?->activeFirmUser();

                            if ($firmUser === null) {
                                return [];
                            }

                            return app(TenantContextService::class)->runWithFirmContext(
                                (int) $firmUser->firm_id,
                                fn (): array => LeadSource::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
                            );
                        })
                        ->searchable()
                        ->nullable(),
                    Select::make('practice_area_interest_id')
                        ->label('Practice Area')
                        ->options(fn (): array => PracticeArea::query()->orderBy('name')->pluck('name', 'id')->all())
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
                TextColumn::make('email')->searchable()->placeholder('—'),
                TextColumn::make('phone')->searchable()->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'new' => 'gray',
                        'contacted' => 'info',
                        'consultation_scheduled', 'consultation_held' => 'warning',
                        'converted' => 'success',
                        'lost', 'archived' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('leadSource.name')->label('Source')->placeholder('—'),
                TextColumn::make('assignedTo.name')->label('Assigned To')->placeholder('—'),
                TextColumn::make('convertedClient.display_name')->label('Converted Client')->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => collect(FirmLeadStatus::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => str($case->value)->replace('_', ' ')->headline()])
                        ->all()),
            ])
            ->recordActions([
                MarkLeadContactedAction::make(),
                ScheduleConsultationAction::make(),
                ConvertLeadToClientAction::make(),
                MarkLeadLostAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ConsultationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFirmLeads::route('/'),
            'create' => CreateFirmLead::route('/create'),
            'view' => ViewFirmLead::route('/{record}'),
            'edit' => EditFirmLead::route('/{record}/edit'),
        ];
    }
}
