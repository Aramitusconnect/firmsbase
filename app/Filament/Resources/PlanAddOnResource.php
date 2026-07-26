<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\PlanModuleStatus;
use App\Filament\Actions\Platform\RetirePlanModuleAction;
use App\Filament\Actions\Platform\SetPlanModuleEnabledAction;
use App\Filament\Resources\PlanAddOnResource\Pages\ListPlanAddOns;
use App\Filament\Resources\PlanAddOnResource\Pages\ViewPlanAddOn;
use App\Filament\Resources\PlanResource\Pages\ViewPlan;
use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
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
 * PlanAddOnResource — Phase 3 (FirmsVault Platform Admin Control
 * Center, "Billing and Commercial Administration"). Cross-firm
 * List+View oversight over the add-on subset of `plan_modules` — this
 * codebase has no separate add-ons table or model; App\Models\PlanModule
 * models both bundled and add-on modules via one boolean, `is_addon`
 * (an explicitly approved decision per that migration's own docblock:
 * "add-ons are ordinary plan_modules rows flagged is_addon = true, not
 * a separate table"). This Resource's entire reason to exist is a
 * dedicated add-on-focused view — its query is hard-scoped to
 * is_addon = true, never a duplicate general plan-modules list.
 *
 * No RLS on plan_modules (Global, same as Plan) — ordinary Eloquent
 * ->query() table.
 *
 * No Create/Edit form (add-on rows are created via
 * PlanModuleService::addModule(), out of this checkpoint's scope, per
 * the mission's own "no generic Create/Edit forms" convention).
 * Mutations are the two discrete, purpose-built Enable/Disable
 * (SetPlanModuleEnabledAction) and Retire (RetirePlanModuleAction)
 * actions.
 *
 * IMPORTANT (per this pass's own dispatch instructions): editing a
 * plan's add-ons does NOT retroactively touch any firm's entitlements
 * — that only happens the next time a firm's license is (re-)assigned
 * this plan (EntitlementPlanSyncService, see the Phase 3 architecture
 * investigation §4/§3.3). This is surfaced both in the empty-state copy
 * below and in each mutating action's own modal description, so the UI
 * never implies an instant fleet-wide effect that doesn't actually
 * happen.
 */
class PlanAddOnResource extends Resource
{
    protected static ?string $model = PlanModule::class;

    protected static ?string $slug = 'plan-add-ons';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $navigationLabel = 'Add-ons';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing & Commercial';

    protected static ?string $recordTitleAttribute = 'module_code';

    /**
     * No Policy class is registered for PlanModule anywhere in this
     * codebase — mirrors ConnectionResource/PlatformSubscriptionResource's
     * own manual canAccess() shape.
     */
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

        return app(PlatformStaffAccessPolicyService::class)->canAccessPlatformBilling($admin)->allowed;
    }

    public static function table(Table $table): Table
    {
        return $table
            // The single hard scope that makes this Resource distinct
            // from a general plan-modules list — see this class's own
            // docblock. Applied unconditionally, never overridable by a
            // filter (no "is_addon" filter is offered at all; this
            // Resource IS the add-on-only view).
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_addon', true)->with('plan'))
            ->columns([
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('module_code')
                    ->label('Module code')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),
                IconColumn::make('enabled')
                    ->boolean(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PlanModuleStatus $state): string => Str::headline($state->value))
                    ->color(fn (PlanModuleStatus $state): string => match ($state) {
                        PlanModuleStatus::Active => 'success',
                        PlanModuleStatus::Retired => 'danger',
                    })
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('plan_id')
                    ->label('Plan')
                    ->searchable()
                    ->options(fn (): array => Plan::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')
                    ->options(collect(PlanModuleStatus::cases())
                        ->mapWithKeys(fn (PlanModuleStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                TernaryFilter::make('enabled'),
            ])
            ->recordActions([
                SetPlanModuleEnabledAction::make(),
                RetirePlanModuleAction::make(),
                Action::make('viewPlan')
                    ->label('View plan')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (PlanModule $record): string => ViewPlan::getUrl(['record' => $record->plan]))
                    ->visible(fn (PlanModule $record): bool => $record->plan !== null),
            ])
            ->emptyStateHeading('No add-ons found')
            ->emptyStateDescription('Add-ons are ordinary plan_modules rows flagged is_addon. Changes here affect the plan catalog only; they do not immediately change any firm\'s active entitlements — a firm only picks up a plan\'s current add-on configuration the next time its license is (re-)assigned that plan.')
            ->defaultSort('module_code')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlanAddOns::route('/'),
            'view' => ViewPlanAddOn::route('/{record}'),
        ];
    }
}
