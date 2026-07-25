<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\FirmActivationStatus;
use App\Filament\Resources\FirmResource\Pages\ListFirms;
use App\Filament\Resources\FirmResource\Pages\ViewFirm;
use App\Models\Firm;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * FirmResource — Phase 1 FirmsVault Admin Control Center. Cross-firm,
 * read-only administrative oversight over the `firms` table, mirroring
 * the Firm panel's App\Filament\Firm\Resources\FirmIntegration Resource's established
 * conventions (layered canAccess(), List+View pages only, no
 * Create/Edit forms). Unlike the Firm panel's FirmIntegration Resource, this Resource
 * lives in the platform-admin panel (`App\Filament\Resources`,
 * AdminPanelProvider's discovery path, gated by the `platform_admin`
 * guard) rather than the firm panel.
 *
 * `firms` carries no BelongsToTenant / RLS (it is the tenancy ROOT, not
 * a tenant-owned table — the RLS coverage registry's own full table
 * inventory confirms no RLS policy exists for it yet), so — unlike
 * FirmUserResource, whose sibling
 * service PlatformFirmUserDirectoryService has an extensive docblock
 * explaining why a per-firm loop is required — this Resource can use a
 * completely ordinary Eloquent-backed `->query()` table with no special
 * cross-firm-read handling at all.
 *
 * Columns/filters are drawn only from Firm's REAL fillable columns
 * (name, activation_status, customer_type, deployment_mode, created_at)
 * — no fabricated column. FirmActivationStatus has exactly 3 cases
 * (Draft, Onboarding, Activated) — confirmed by reading the enum
 * directly; there is no Active/Suspended/Trial distinction anywhere on
 * this table (Suspended-style commercial lifecycle state lives on
 * firm_licenses.license_status instead, per FirmActivationStatus's own
 * docblock, and is out of this Resource's scope — it is not a firms
 * column). The activation-status filter below therefore lists exactly
 * the enum's 3 real cases rather than the "active/suspended/trial"
 * vocabulary a filter might otherwise be assumed to use.
 */
class FirmResource extends Resource
{
    protected static ?string $model = Firm::class;

    protected static ?string $slug = 'firms';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Firms';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Policy-driven (FirmPolicy::viewAny(), registered in
     * PlatformAdminPolicyServiceProvider) — Filament's own default
     * canAccess()/canViewAny() resolves this via Gate::check() against
     * the current panel's authenticated PlatformAdmin automatically, the
     * same mechanism the Firm panel's FirmIntegration Resource relies on for its own
     * base-layer check. FirmPolicy::viewAny() itself delegates to
     * PlatformStaffAccessPolicyService::canAccessPlatformAdministration()
     * — so this Resource is both "Policy-driven via FirmPolicy" and
     * "delegates to canAccessPlatformAdministration()" through the same
     * single check, rather than duplicating that check a second time
     * here on top of the Policy.
     */
    public static function canAccess(): bool
    {
        return parent::canAccess();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('activation_status')
                    ->label('Activation status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'activated' => 'success',
                        'onboarding' => 'warning',
                        'draft' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('customer_type')
                    ->label('Customer type')
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->sortable(),
                TextColumn::make('deployment_mode')
                    ->label('Deployment mode')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('activation_status')
                    ->label('Activation status')
                    ->options(collect(FirmActivationStatus::cases())
                        ->mapWithKeys(fn (FirmActivationStatus $status): array => [$status->value => ucfirst($status->value)])
                        ->all()),
                SelectFilter::make('customer_type')
                    ->label('Customer type')
                    ->options(collect(CustomerType::cases())
                        ->mapWithKeys(fn (CustomerType $type): array => [$type->value => ucfirst(str_replace('_', ' ', $type->value))])
                        ->all()),
                SelectFilter::make('deployment_mode')
                    ->label('Deployment mode')
                    ->options(collect(DeploymentMode::cases())
                        ->mapWithKeys(fn (DeploymentMode $mode): array => [$mode->value => ucfirst(str_replace('_', ' ', $mode->value))])
                        ->all()),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFirms::route('/'),
            'view' => ViewFirm::route('/{record}'),
        ];
    }
}
