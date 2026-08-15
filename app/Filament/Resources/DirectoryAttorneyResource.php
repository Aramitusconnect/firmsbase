<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\Platform\ArchiveDirectoryAttorneyAction;
use App\Filament\Actions\Platform\AssociateDirectoryAttorneyWithFirmAction;
use App\Filament\Actions\Platform\PublishDirectoryAttorneyAction;
use App\Filament\Actions\Platform\UnpublishDirectoryAttorneyAction;
use App\Filament\Resources\DirectoryAttorneyResource\Pages\CreateDirectoryAttorney;
use App\Filament\Resources\DirectoryAttorneyResource\Pages\EditDirectoryAttorney;
use App\Filament\Resources\DirectoryAttorneyResource\Pages\ListDirectoryAttorneys;
use App\Filament\Resources\DirectoryAttorneyResource\Pages\ViewDirectoryAttorney;
use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryAttorneyFirmRelationshipState;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Enums\VerificationState;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryVerification;
use App\Marketplace\Services\MarketplaceImportDuplicateDetectionService;
use App\Models\Language;
use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * DirectoryAttorneyResource — MyAttorney SuperAdmin console
 * professionalization mission (MYAT3). The model/schema
 * (App\Marketplace\Models\DirectoryAttorney) already existed —
 * confirmed by this mission's own discovery pass — but no Filament
 * resource anywhere in the codebase managed it (only a public
 * read-only profile controller). This is the first admin-facing
 * surface for it, deliberately built to mirror
 * DirectoryFirmResource's exact shape (same nav group, same
 * canManageMarketplaceGovernance() gate, same List/View/Create/Edit
 * page split, same "moderation via named Action classes" convention)
 * rather than inventing a different pattern for a sibling resource.
 */
class DirectoryAttorneyResource extends Resource
{
    protected static ?string $model = DirectoryAttorney::class;

    protected static ?string $slug = 'directory-attorneys';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Directory Attorneys';

    protected static string|\UnitEnum|null $navigationGroup = 'MyAttorney Marketplace';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canManageMarketplaceGovernance($admin)->allowed;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['firmRelationships' => fn ($q) => $q->where('relationship_state', DirectoryAttorneyFirmRelationshipState::Current)->with('firm')]);
    }

    /**
     * @return array{attorney: DirectoryAttorney, reasons: array<int, string>}|null
     */
    private static function duplicateCandidate(callable $get, ?DirectoryAttorney $record): ?array
    {
        $data = [
            'name' => (string) ($get('name') ?? ''),
            'bar_number' => $get('bar_number'),
        ];

        if (blank($data['name']) && blank($data['bar_number'])) {
            return null;
        }

        return app(MarketplaceImportDuplicateDetectionService::class)->findAttorneyDuplicateCandidate($data, $record?->id);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Attorney Details')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255)->live(onBlur: true),
                    TextInput::make('title')->maxLength(255)->helperText('e.g. Partner, Associate.'),
                    TextInput::make('bar_number')->label('Bar Number')->maxLength(255)->live(onBlur: true),
                    TagsInput::make('license_jurisdictions')
                        ->label('License Jurisdictions')
                        ->placeholder('e.g. MI, OH')
                        ->helperText('Press enter after each jurisdiction.'),
                ]),
            /**
             * MyAttorney final hardening mission, finding 7. Add Attorney
             * had no duplicate check of any kind before this mission —
             * mirrors DirectoryFirmResource's own duplicate_warning +
             * duplicate_override_reason pattern exactly, using the
             * genuinely new findAttorneyDuplicateCandidate() (name +
             * bar_number — the only two signals a DirectoryAttorney
             * record carries).
             */
            Placeholder::make('duplicate_warning')
                ->hiddenLabel()
                ->content(function (callable $get, ?DirectoryAttorney $record): string {
                    $candidate = self::duplicateCandidate($get, $record);

                    if ($candidate === null) {
                        return '';
                    }

                    $match = $candidate['attorney'];
                    $reasons = implode('; ', $candidate['reasons']);

                    return "⚠ Possible duplicate: an existing attorney \"{$match->name}\" (slug: {$match->slug}) — matched because: {$reasons}.";
                })
                ->visible(fn (callable $get): bool => filled($get('name')) || filled($get('bar_number'))),
            Textarea::make('duplicate_override_reason')
                ->label('Reason for creating despite possible duplicate')
                ->rows(2)
                ->maxLength(500)
                ->visible(fn (callable $get, ?DirectoryAttorney $record): bool => $record === null && self::duplicateCandidate($get, $record) !== null)
                ->required(fn (callable $get, ?DirectoryAttorney $record): bool => $record === null && self::duplicateCandidate($get, $record) !== null)
                ->helperText('Required because a possible duplicate attorney was found above. Explain why this is a genuinely different person.'),
            Section::make('Biography')
                ->schema([
                    Textarea::make('biography')->rows(4)->maxLength(2000),
                ]),
            Section::make('Firm Association')
                ->columns(2)
                ->schema([
                    Select::make('directory_firm_id')
                        ->label('Firm')
                        ->options(fn (): array => DirectoryFirm::query()->orderBy('display_name')->limit(200)->pluck('display_name', 'id')->all())
                        ->searchable()
                        ->helperText('Only used when creating. Use "Manage Firm Association" to change it later.')
                        ->visible(fn (?DirectoryAttorney $record) => $record === null),
                    TextInput::make('firm_title')
                        ->label('Title at Firm')
                        ->maxLength(255)
                        ->visible(fn (?DirectoryAttorney $record) => $record === null),
                ]),
            Section::make('Practice Areas & Languages')
                ->columns(2)
                ->schema([
                    Select::make('practice_area_ids')
                        ->label('Practice Areas')
                        ->multiple()
                        ->options(fn () => PracticeArea::query()->where('is_marketplace_visible', true)->orderBy('sort_order')->pluck('name', 'id')->all())
                        ->default(fn (?DirectoryAttorney $record) => $record?->practiceAreas()->pluck('practice_areas.id')->all() ?? []),
                    Select::make('language_ids')
                        ->label('Languages')
                        ->multiple()
                        ->options(fn () => Language::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (?DirectoryAttorney $record) => $record?->languages()->pluck('languages.id')->all() ?? []),
                ]),
            Section::make('Publication')
                ->schema([
                    Select::make('publication_state')
                        ->label('Publication Status')
                        ->options([
                            DirectoryPublicationState::Draft->value => 'Draft',
                            DirectoryPublicationState::Published->value => 'Published',
                        ])
                        ->default(DirectoryPublicationState::Draft->value)
                        ->required()
                        ->native(false)
                        ->helperText('Publish/Unpublish/Archive are separate actions from the View page once created.')
                        ->visible(fn (?DirectoryAttorney $record) => $record === null),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Attorney')->searchable()->sortable(),
                TextColumn::make('current_firm')
                    ->label('Firm')
                    ->state(fn (DirectoryAttorney $record) => $record->firmRelationships->first()?->firm?->display_name ?? '—'),
                TextColumn::make('practiceAreas.name')->label('Practice Areas')->listWithLineBreaks()->limit(2)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('bar_number')->label('Bar #')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('publication_state')
                    ->badge()
                    ->formatStateUsing(fn (DirectoryPublicationState $state): string => Str::headline($state->value))
                    ->color(fn (DirectoryPublicationState $state): string => match ($state) {
                        DirectoryPublicationState::Published => 'success',
                        DirectoryPublicationState::Draft => 'gray',
                        DirectoryPublicationState::Suspended => 'warning',
                        DirectoryPublicationState::Removed, DirectoryPublicationState::Archived => 'danger',
                    })
                    ->sortable(),
                IconColumn::make('is_verified')
                    ->label('Verified')
                    ->boolean()
                    ->state(fn (DirectoryAttorney $record): bool => DirectoryVerification::query()
                        ->where('verifiable_type', DirectoryAttorney::class)
                        ->where('verifiable_id', $record->id)
                        ->whereIn('dimension', [VerificationDimension::AttorneyIdentity->value, VerificationDimension::AttorneyLicense->value])
                        ->where('state', VerificationState::Verified->value)
                        ->exists())
                    ->tooltip('Whether a SuperAdmin has reviewed evidence and verified this attorney\'s identity or license.'),
                TextColumn::make('source_type')
                    ->label('Source')
                    ->formatStateUsing(fn (DataProvenanceSourceType $state): string => Str::headline($state->value))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_verified_at')->label('Last verified')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Updated')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('publication_state')
                    ->options(collect(DirectoryPublicationState::cases())->mapWithKeys(fn (DirectoryPublicationState $s) => [$s->value => Str::headline($s->value)])->all()),
                TernaryFilter::make('has_firm')
                    ->label('Has firm association')
                    ->queries(
                        true: fn ($query) => $query->whereHas('firmRelationships', fn ($q) => $q->where('relationship_state', DirectoryAttorneyFirmRelationshipState::Current)),
                        false: fn ($query) => $query->whereDoesntHave('firmRelationships', fn ($q) => $q->where('relationship_state', DirectoryAttorneyFirmRelationshipState::Current)),
                    ),
                SelectFilter::make('practiceAreas')
                    ->label('Practice Area')
                    ->relationship('practiceAreas', 'name')
                    ->searchable(),
                SelectFilter::make('source_type')
                    ->label('Source')
                    ->options(collect(DataProvenanceSourceType::cases())->mapWithKeys(fn (DataProvenanceSourceType $s) => [$s->value => Str::headline($s->value)])->all()),
            ])
            ->recordActions([
                PublishDirectoryAttorneyAction::make(),
                UnpublishDirectoryAttorneyAction::make(),
                AssociateDirectoryAttorneyWithFirmAction::make(),
                ArchiveDirectoryAttorneyAction::make(),
            ])
            ->emptyStateHeading('No directory attorneys yet')
            ->emptyStateDescription('Attorneys are usually added via a firm\'s own profile or CSV import. Add one manually if needed.')
            ->defaultSort('name')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDirectoryAttorneys::route('/'),
            'create' => CreateDirectoryAttorney::route('/create'),
            'view' => ViewDirectoryAttorney::route('/{record}'),
            'edit' => EditDirectoryAttorney::route('/{record}/edit'),
        ];
    }
}
