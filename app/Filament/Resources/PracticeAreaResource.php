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
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
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
                    ->searchable()
                    ->sortable(),
                TextColumn::make('matterTypes_count')
                    ->label('Matter types')
                    ->counts('matterTypes'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
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
