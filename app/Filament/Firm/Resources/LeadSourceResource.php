<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Filament\Firm\Resources\LeadSourceResource\Actions\DeactivateLeadSourceAction;
use App\Filament\Firm\Resources\LeadSourceResource\Actions\ReactivateLeadSourceAction;
use App\Filament\Firm\Resources\LeadSourceResource\Pages\CreateLeadSource;
use App\Filament\Firm\Resources\LeadSourceResource\Pages\EditLeadSource;
use App\Filament\Firm\Resources\LeadSourceResource\Pages\ListLeadSources;
use App\Models\LeadSource;
use App\Services\ClientCrmAccessPolicyService;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * LeadSourceResource — FirmsVault staging follow-up addition
 * ("Application Completion — Catalogs + Firm-Owned Reference Data").
 * "Firm Management → Lead Sources" per this mission's own required
 * navigation shape. LeadSource is intentionally FIRM-SCOPED, never
 * global (see that model's own docblock) — every row this Resource
 * ever shows/creates belongs to the acting firm only, enforced by
 * permanent FORCE ROW LEVEL SECURITY at the database layer regardless
 * of this UI-layer scoping.
 *
 * Every mutation routes through LeadSourceService — never a bare
 * Eloquent form submission — mirroring ExpenseCategoryResource's own
 * discipline.
 */
class LeadSourceResource extends Resource
{
    protected static ?string $model = LeadSource::class;

    protected static ?string $slug = 'lead-sources';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Lead Sources';

    protected static string|\UnitEnum|null $navigationGroup = 'Firm Management';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return parent::canAccess() && static::isFirmAuthorized();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::isFirmAuthorized();
    }

    public static function isFirmAuthorized(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(ClientCrmAccessPolicyService::class)->canManageLeadSourceCatalog($firmUser->role);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lead Source')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('code')
                        ->label('Code')
                        ->helperText('A short, stable identifier, e.g. "referral_client".')
                        ->required()
                        ->maxLength(255)
                        ->alphaDash(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->recordActions([
                DeactivateLeadSourceAction::make(),
                ReactivateLeadSourceAction::make(),
            ])
            ->emptyStateHeading('No lead sources yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeadSources::route('/'),
            'create' => CreateLeadSource::route('/create'),
            'edit' => EditLeadSource::route('/{record}/edit'),
        ];
    }
}
