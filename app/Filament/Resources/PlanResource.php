<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\BillingInterval;
use App\Enums\PlanStatus;
use App\Filament\Actions\Platform\ActivatePlanAction;
use App\Filament\Actions\Platform\ArchivePlanAction;
use App\Filament\Actions\Platform\EditPlanAction;
use App\Filament\Resources\PlanResource\Pages\ListPlans;
use App\Filament\Resources\PlanResource\Pages\ViewPlan;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Support\MoneyDisplay;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlanResource — Phase 3 (FirmsVault Platform Admin Control Center,
 * "Billing and Commercial Administration"). Cross-firm List+View
 * oversight over `plans`, the GLOBAL admin-managed commercial plan
 * catalog (no firm_id, no RLS — see Plan's own docblock). Ordinary
 * Eloquent ->query() table, same shape as FirmResource/
 * PlatformAdministratorResource.
 *
 * FIRMSVAULT — STAGING ADMIN STABILIZATION revision: Create/Edit are
 * now supported, per this pass's own defect list ("PlanResource has no
 * supported Create Plan action"), reversing the earlier Phase 3
 * decision recorded below for historical context. Both are still
 * purpose-built actions (CreatePlanAction/EditPlanAction) routed
 * through PlanService — never Filament's generic CreateAction/
 * EditAction — matching every other mutation in this Resource
 * (Activate/Archive).
 *
 * Historical note (Phase 3, superseded above): "building a brand-new
 * Plan from scratch has pricing/catalog implications beyond this
 * phase's scope; only activate/archive existing plans."
 *
 * Price column renders via App\Support\MoneyDisplay::fromCents() —
 * never a bare number_format/raw integer (this Resource is the
 * required MoneyDisplay rendering spot-check per this pass's own
 * testing instructions).
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $slug = 'plans';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Plans';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing & Commercial';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * No Policy class is registered for Plan anywhere in this codebase
     * — mirrors ConnectionResource/PlatformSubscriptionResource's own
     * manual canAccess() shape.
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
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PlanStatus $state): string => Str::headline($state->value))
                    ->color(fn (PlanStatus $state): string => match ($state) {
                        PlanStatus::Active => 'success',
                        PlanStatus::Draft => 'gray',
                        PlanStatus::Archived => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('price_cents')
                    ->label('Price')
                    ->formatStateUsing(fn (?int $state): string => MoneyDisplay::fromCents($state))
                    ->sortable(),
                TextColumn::make('billing_interval')
                    ->label('Billing interval')
                    ->formatStateUsing(fn (BillingInterval $state): string => Str::headline($state->value))
                    ->sortable(),
                TextColumn::make('support_access_level')
                    ->label('Support access level')
                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                TextColumn::make('trial_days')
                    ->label('Trial days')
                    ->sortable(),
                IconColumn::make('trial_requires_card')
                    ->label('Trial requires card')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(PlanStatus::cases())
                        ->mapWithKeys(fn (PlanStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                SelectFilter::make('billing_interval')
                    ->label('Billing interval')
                    ->options(collect(BillingInterval::cases())
                        ->mapWithKeys(fn (BillingInterval $interval): array => [$interval->value => Str::headline($interval->value)])
                        ->all()),
            ])
            ->recordActions([
                EditPlanAction::make(),
                ActivatePlanAction::make(),
                ArchivePlanAction::make(),
            ])
            ->emptyStateHeading('No plans found')
            ->emptyStateDescription('Plans are the platform commercial catalog — created out-of-band, managed here.')
            ->defaultSort('name')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'view' => ViewPlan::route('/{record}'),
        ];
    }
}
