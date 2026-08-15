<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\Platform\ActivateMarketplaceMembershipAction;
use App\Filament\Actions\Platform\DeactivateMarketplaceMembershipAction;
use App\Filament\Actions\Platform\PublishDirectoryFirmAction;
use App\Filament\Actions\Platform\RemoveDirectoryFirmAction;
use App\Filament\Actions\Platform\SuspendDirectoryFirmAction;
use App\Filament\Resources\DirectoryFirmResource\Pages\CreateDirectoryFirm;
use App\Filament\Resources\DirectoryFirmResource\Pages\EditDirectoryFirm;
use App\Filament\Resources\DirectoryFirmResource\Pages\ListDirectoryFirms;
use App\Filament\Resources\DirectoryFirmResource\Pages\ViewDirectoryFirm;
use App\Marketplace\Enums\ConsultationMode;
use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Enums\VerificationState;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryVerification;
use App\Marketplace\Services\MarketplaceImportDuplicateDetectionService;
use App\Models\Language;
use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * DirectoryFirmResource — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 11 (sections 56-58). Cross-firm List+View oversight over
 * `directory_firms`, the GLOBAL marketplace catalog (no firm_id RLS
 * scoping — see that table's own migration docblock). Ordinary
 * Eloquent ->query(), same shape as PlanResource.
 *
 * Reuses the existing Admin Control Center (no new panel) and the
 * existing PlatformStaffAccessPolicyService gating convention — see
 * canManageMarketplaceGovernance()'s own docblock for why this is a
 * single-role-split gate with no separate read-only audience.
 */
class DirectoryFirmResource extends Resource
{
    protected static ?string $model = DirectoryFirm::class;

    protected static ?string $slug = 'directory-firms';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'Directory Firms';

    protected static string|\UnitEnum|null $navigationGroup = 'MyAttorney Marketplace';

    protected static ?string $recordTitleAttribute = 'display_name';

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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('attorneyRelationships');
    }

    /**
     * No DirectoryFirm Policy class exists (this resource has never
     * needed one — see canViewAny()'s own docblock on why marketplace
     * governance is a single-role-split gate). Filament's default
     * canCreate()/canEdit() fall through to a Policy/Gate lookup that
     * would either throw or silently default-allow for a policy-less
     * model — both wrong here. Explicit overrides mirroring
     * canViewAny() exactly, so Create/Edit are gated identically to
     * List/View rather than relying on that fallback.
     */
    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    /**
     * @return array{firm: DirectoryFirm, reasons: array<int, string>}|null
     */
    private static function duplicateCandidate(callable $get, ?DirectoryFirm $record): ?array
    {
        $data = [
            'name_normalized' => Str::lower((string) ($get('display_name') ?? '')),
            'phone' => $get('phone'),
            'website' => $get('website'),
        ];

        if (blank($data['name_normalized']) && blank($data['phone']) && blank($data['website'])) {
            return null;
        }

        return app(MarketplaceImportDuplicateDetectionService::class)->findDuplicateCandidate($data, $record?->id);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Listing Details')
                ->columns(2)
                ->schema([
                    TextInput::make('display_name')->label('Firm Name')->required()->maxLength(255)->live(onBlur: true),
                    TextInput::make('slug')->maxLength(255)->helperText('Leave blank to auto-generate from the firm name.'),
                    TextInput::make('legal_name')->label('Legal Name')->maxLength(255)->helperText('Leave blank to use the firm name.'),
                    TextInput::make('phone')->tel()->maxLength(255)->live(onBlur: true),
                    TextInput::make('website')->url()->maxLength(255)->live(onBlur: true),
                    TextInput::make('public_email')->label('Public Email')->email()->maxLength(255),
                    TextInput::make('founding_year')->numeric()->minValue(1800)->maxValue((int) date('Y')),
                    Toggle::make('accepting_inquiries')->label('Accepting new inquiries')->default(true),
                ]),
            Placeholder::make('duplicate_warning')
                ->hiddenLabel()
                ->content(function (callable $get, ?DirectoryFirm $record): string {
                    $candidate = self::duplicateCandidate($get, $record);

                    if ($candidate === null) {
                        return '';
                    }

                    $match = $candidate['firm'];
                    $reasons = implode('; ', $candidate['reasons']);

                    return "⚠ Possible duplicate: an existing listing \"{$match->display_name}\" (slug: {$match->slug}) — matched because: {$reasons}.";
                })
                ->visible(fn (callable $get): bool => filled($get('display_name')) || filled($get('phone')) || filled($get('website'))),
            /**
             * MyAttorney final hardening mission, finding 7. Only shown
             * when creating (never on Edit — this mission's own scope is
             * "Manual Add Firm/Add Attorney") AND a duplicate candidate
             * is actually present. Required whenever visible, so a
             * SuperAdmin cannot create past a detected duplicate without
             * a deliberate, audited justification — see
             * DirectoryFirmAdministrationService::create() for the
             * server-side enforcement (this closure is not the only
             * gate; the service refuses creation without a reason too).
             */
            Textarea::make('duplicate_override_reason')
                ->label('Reason for creating despite possible duplicate')
                ->rows(2)
                ->maxLength(500)
                ->visible(fn (callable $get, ?DirectoryFirm $record): bool => $record === null && self::duplicateCandidate($get, $record) !== null)
                ->required(fn (callable $get, ?DirectoryFirm $record): bool => $record === null && self::duplicateCandidate($get, $record) !== null)
                ->helperText('Required because a possible duplicate listing was found above. Explain why this is a genuinely different firm.'),
            Section::make('Description')
                ->schema([
                    Textarea::make('description')->rows(4)->maxLength(2000),
                ]),
            Section::make('Primary Office Address')
                ->columns(2)
                ->schema([
                    TextInput::make('address_line1')->label('Address Line 1')->maxLength(255),
                    TextInput::make('address_line2')->label('Address Line 2')->maxLength(255),
                    TextInput::make('city')->maxLength(255),
                    TextInput::make('state')->maxLength(255),
                    TextInput::make('postal_code')->label('ZIP / Postal Code')->maxLength(20),
                    TextInput::make('country')->maxLength(2)->default('US')->helperText('2-letter country code.'),
                ]),
            Section::make('Consultation Modes')
                ->schema([
                    CheckboxList::make('consultation_modes')
                        ->hiddenLabel()
                        ->options(array_combine(
                            array_map(fn (ConsultationMode $m) => $m->value, ConsultationMode::cases()),
                            ['In Person', 'Phone', 'Video'],
                        )),
                ]),
            Section::make('Practice Areas & Languages')
                ->columns(2)
                ->schema([
                    Select::make('practice_area_ids')
                        ->label('Practice Areas')
                        ->multiple()
                        ->options(fn () => PracticeArea::query()->where('is_marketplace_visible', true)->orderBy('sort_order')->pluck('name', 'id')->all())
                        ->default(fn (?DirectoryFirm $record) => $record?->practiceAreas()->pluck('practice_areas.id')->all() ?? []),
                    Select::make('language_ids')
                        ->label('Languages')
                        ->multiple()
                        ->options(fn () => Language::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (?DirectoryFirm $record) => $record?->languages()->pluck('languages.id')->all() ?? []),
                ]),
            Section::make('Publication')
                ->columns(2)
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
                        ->helperText('Suspend/Remove are separate moderation actions from the View page, not available here.'),
                    Placeholder::make('source_info')
                        ->label('Source')
                        ->content(fn (?DirectoryFirm $record) => $record !== null
                            ? Str::headline($record->source_type->value).' — cannot be changed here.'
                            : Str::headline(DataProvenanceSourceType::AdminEntered->value).' (every record created through this form is stamped this way).'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')->label('Firm')->searchable()->sortable(),
                TextColumn::make('slug')->searchable()->toggleable(isToggledHiddenByDefault: true),
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
                IconColumn::make('is_claimed')
                    ->label('Claimed')
                    ->boolean()
                    ->tooltip('Whether a firm representative has claimed ownership of this listing — distinct from Verified (evidence-reviewed authority) and Member (paid FirmsVault membership).'),
                IconColumn::make('is_verified')
                    ->label('Verified')
                    ->boolean()
                    ->state(fn (DirectoryFirm $record): bool => DirectoryVerification::query()
                        ->where('verifiable_type', DirectoryFirm::class)
                        ->where('verifiable_id', $record->id)
                        ->where('dimension', VerificationDimension::FirmAuthority->value)
                        ->where('state', VerificationState::Verified->value)
                        ->exists())
                    ->tooltip('Whether a SuperAdmin has reviewed evidence and verified this firm\'s authority — distinct from Claimed (self-asserted ownership) and Member (paid FirmsVault membership).'),
                IconColumn::make('is_marketplace_member')
                    ->label('Member')
                    ->boolean()
                    ->tooltip('Whether this firm has an active paid FirmsVault membership — distinct from Claimed (self-asserted ownership) and Verified (evidence-reviewed authority).'),
                IconColumn::make('accepting_inquiries')->label('Accepting inquiries')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('completeness_score')->label('Completeness')->sortable(),
                TextColumn::make('offices.city')->label('City')->listWithLineBreaks()->limit(1)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('offices.state')->label('State')->listWithLineBreaks()->limit(1)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('practiceAreas.name')->label('Practice Areas')->listWithLineBreaks()->limit(2)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('attorney_relationships_count')->label('Attorneys')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_type')
                    ->label('Source')
                    ->formatStateUsing(fn (DataProvenanceSourceType $state): string => Str::headline($state->value))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_verified_at')->label('Last verified')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Last updated')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('publication_state')
                    ->options(collect(DirectoryPublicationState::cases())->mapWithKeys(fn (DirectoryPublicationState $s) => [$s->value => Str::headline($s->value)])->all()),
                TernaryFilter::make('is_claimed'),
                TernaryFilter::make('is_marketplace_member')->label('Member'),
                TernaryFilter::make('accepting_inquiries')->label('Accepting inquiries'),
                SelectFilter::make('offices')
                    ->label('State')
                    ->relationship('offices', 'state')
                    ->searchable(),
                SelectFilter::make('practiceAreas')
                    ->label('Practice Area')
                    ->relationship('practiceAreas', 'name')
                    ->searchable(),
                SelectFilter::make('source_type')
                    ->label('Source')
                    ->options(collect(DataProvenanceSourceType::cases())->mapWithKeys(fn (DataProvenanceSourceType $s) => [$s->value => Str::headline($s->value)])->all()),
                SelectFilter::make('completeness')
                    ->label('Completeness')
                    ->options(['low' => 'Below 60', 'high' => '60 or above'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'low' => $query->where('completeness_score', '<', 60),
                        'high' => $query->where('completeness_score', '>=', 60),
                        default => $query,
                    }),
            ])
            ->recordActions([
                PublishDirectoryFirmAction::make(),
                SuspendDirectoryFirmAction::make(),
                ActivateMarketplaceMembershipAction::make(),
                DeactivateMarketplaceMembershipAction::make(),
                RemoveDirectoryFirmAction::make(),
            ])
            ->emptyStateHeading('No directory firms yet')
            ->emptyStateDescription('Import directory data or create a firm manually.')
            ->defaultSort('display_name')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDirectoryFirms::route('/'),
            'create' => CreateDirectoryFirm::route('/create'),
            'view' => ViewDirectoryFirm::route('/{record}'),
            'edit' => EditDirectoryFirm::route('/{record}/edit'),
        ];
    }
}
