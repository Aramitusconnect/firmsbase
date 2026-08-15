<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\Platform\ActivatePracticeAreaAction;
use App\Filament\Actions\Platform\DeactivatePracticeAreaAction;
use App\Filament\Actions\Platform\EditPracticeAreaAction;
use App\Filament\Resources\PracticeAreaResource\Pages\ListPracticeAreas;
use App\Filament\Resources\PracticeAreaResource\Pages\ViewPracticeArea;
use App\Filament\Resources\PracticeAreaResource\RelationManagers\MatterTypesRelationManager;
use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Services\Configuration\PracticeAreaCanonicalizationService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * PracticeAreaResource — FirmsVault staging follow-up ("Application
 * Completion — Catalogs + Firm-Owned Reference Data"). Platform Admin
 * List+View over the GLOBAL `practice_areas` catalog (no firm_id, no
 * RLS — see PracticeArea's own docblock). Ordinary Firm users can
 * SELECT from this catalog (via AddMatterAction/AddClientAction/
 * FirmLeadResource) but can never create/edit/deactivate an entry —
 * there is no such capability anywhere in the Firm panel.
 *
 * Create/Edit/Activate/Deactivate are all purpose-built Actions
 * (CreatePracticeAreaAction/EditPracticeAreaAction/
 * ActivatePracticeAreaAction/DeactivatePracticeAreaAction) routed
 * through PracticeAreaService — never Filament's generic
 * CreateAction/EditAction — matching PlanResource's exact discipline.
 * Deactivation is a soft state flip (is_active=false), never a
 * destructive delete: a practice area already referenced by a Matter/
 * FirmPracticeArea/TemplatePack must remain a valid foreign key target
 * forever (see PracticeAreaService's own docblock).
 *
 * Matter Types are managed nested under this resource's View page (a
 * MatterTypesRelationManager tab) — "Practice Area → Matter Types" per
 * this mission's own required navigation shape — never as an
 * independent top-level Matter Type resource.
 */
class PracticeAreaResource extends Resource
{
    protected static ?string $model = PracticeArea::class;

    protected static ?string $slug = 'practice-areas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Practice Areas';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canManagePracticeAreaCatalog($admin)->allowed;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Canonical code')
                    ->searchable()
                    ->sortable()
                    ->description(fn (PracticeArea $record): ?string => $record->slug === null
                        ? null
                        : 'slug: '.$record->slug),
                TextColumn::make('matterTypes_count')
                    ->label('Matter types')
                    ->counts('matterTypes')
                    ->sortable(),
                /**
                 * Stored alias COUNT only. `practice_areas.synonyms` is a
                 * real column, but no resolver in this codebase consults
                 * it (MarketplaceSearchService's own docblock says so
                 * explicitly), so this column is labelled "Stored
                 * aliases" and the View page states plainly that they do
                 * not resolve. Mission section 100: never present stored
                 * data as a working capability.
                 */
                TextColumn::make('synonyms')
                    ->label('Stored aliases')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => is_array($state) && $state !== []
                        ? count($state).' stored'
                        : 'None')
                    ->color(fn (mixed $state): string => is_array($state) && $state !== [] ? 'info' : 'gray')
                    ->toggleable(),
                IconColumn::make('is_marketplace_visible')
                    ->label('Marketplace')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    // Textual, not colour-only — mission section 86.
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('is_marketplace_visible')->label('Marketplace visible'),
                Filter::make('has_matter_types')
                    ->label('Has matter types')
                    ->query(fn (Builder $query): Builder => $query->has('matterTypes')),
                Filter::make('has_stored_aliases')
                    ->label('Has stored aliases')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('synonyms')
                        ->whereRaw('json_array_length(synonyms::json) > 0')),
                /**
                 * Suspected duplicates cannot be expressed as a plain SQL
                 * predicate — the rule is "normalizes onto another row",
                 * which is a comparison between rows. The ids are
                 * resolved once by the canonical analysis service and
                 * then applied as a whereKey filter, so filtering still
                 * happens in the database (mission section 82) rather
                 * than by loading the catalog into PHP and filtering
                 * there. The catalog is small, global reference data —
                 * a few dozen rows — so this stays cheap.
                 */
                Filter::make('suspected_duplicate')
                    ->label('Suspected duplicate')
                    ->query(function (Builder $query): Builder {
                        $ids = app(PracticeAreaCanonicalizationService::class)
                            ->suspectedDuplicatePairs()
                            ->flatMap(fn (array $pair): array => [$pair['lower']->id, $pair['higher']->id])
                            ->unique()
                            ->values()
                            ->all();

                        return $query->whereKey($ids === [] ? [0] : $ids);
                    }),
            ])
            ->recordActions([
                EditPracticeAreaAction::make(),
                ActivatePracticeAreaAction::make(),
                DeactivatePracticeAreaAction::make(),
            ])
            ->emptyStateHeading('No practice areas found')
            ->emptyStateDescription('The global practice area catalog every firm selects from when creating a Matter, Client, or Lead.')
            ->defaultSort('name')
            ->paginated([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [
            MatterTypesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPracticeAreas::route('/'),
            'view' => ViewPracticeArea::route('/{record}'),
        ];
    }
}
