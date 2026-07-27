<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\EntitlementSource;
use App\Filament\Actions\Platform\SetEntitlementOverrideAction;
use App\Filament\Resources\EntitlementOverrideResource\Pages\ListEntitlementOverrides;
use App\Filament\Resources\EntitlementOverrideResource\Pages\ViewEntitlementOverride;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\ModuleCatalog;
use App\Models\PlatformAdmin;
use App\Services\PlatformEntitlementOverrideDirectoryService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * EntitlementOverrideResource ("Entitlement Overrides") — Phase 4
 * (FirmsVault Platform Admin Control Center, "Configuration" category).
 * The honest relabeling of "Feature Flags": this codebase has no
 * independent flags table (approved decision, see
 * DeploymentFeatureFlagAuditService's own docblock: "'feature flag'
 * means the EXISTING firm_entitlements/EntitlementSource mechanism; no
 * second feature-flag or audit system is introduced") — this resource
 * is built against the real, per-firm `firm_entitlements` table
 * instead, never a fabricated global-flags concept.
 *
 * Precedence (highest wins, shown in the UI, never silently omitted):
 * admin_override > firm_override > org_inherited > plan
 * (EntitlementSource::precedence()). Every row here shows its own
 * source and computed precedence so an admin can see at a glance
 * whether the override they are looking at is actually the one
 * currently winning for that (firm, module) pair.
 *
 * Does NOT duplicate Phase 1's FirmResource — that resource
 * deliberately left FirmEntitlement/Plan-related fields off (confirmed
 * in this phase's own architecture investigation).
 *
 * List+View only, with exactly one mutating action: Set Override (a
 * HEADER action, not a row action — see SetEntitlementOverrideAction's
 * own docblock for why: a new override may target a module the firm
 * has no existing row for at all).
 */
class EntitlementOverrideResource extends Resource
{
    /**
     * See SyncFailureResource's own docblock for why a real model is set
     * here (framework label metadata only) while canAccess() below is
     * still fully self-contained and never calls parent::canAccess().
     */
    protected static ?string $model = FirmEntitlement::class;

    protected static ?string $slug = 'entitlement-overrides';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Entitlement Overrides';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 70;

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessEntitlementOverrides($admin)->allowed;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters): Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                $filters ??= [];

                try {
                    $rows = app(PlatformEntitlementOverrideDirectoryService::class)->listEntitlements($admin, [
                        'firm_uuid' => $filters['firm_uuid']['value'] ?? null,
                        'module_code' => $filters['module_code']['value'] ?? null,
                        'source' => $filters['source']['value'] ?? null,
                    ]);
                } catch (RuntimeException) {
                    return collect();
                }

                return $rows->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                SelectFilter::make('module_code')
                    ->label('Module')
                    ->searchable()
                    ->options(fn (): array => ModuleCatalog::query()->orderBy('module_name')->pluck('module_name', 'module_code')->all()),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options(collect(EntitlementSource::cases())
                        ->mapWithKeys(fn (EntitlementSource $source): array => [$source->value => Str::headline($source->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('module_code')->label('Module'),
                IconColumn::make('enabled')->label('Enabled')->boolean(),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state))
                    ->color(fn (?string $state): string => match ($state) {
                        EntitlementSource::AdminOverride->value => 'danger',
                        EntitlementSource::FirmOverride->value => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('precedence')->label('Precedence')->alignEnd(),
                TextColumn::make('ends_at')->label('Ends at')->dateTime()->placeholder('No end date'),
                TextColumn::make('updated_at')->label('Last updated')->dateTime(),
            ])
            ->headerActions([
                SetEntitlementOverrideAction::make(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewEntitlementOverride::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No entitlement records found')
            ->emptyStateDescription('This is the real, per-firm entitlement mechanism behind "feature flags" in this codebase — not a global toggle system. Use Set Override above to create a firm_override or admin_override record for a specific firm and module.')
            ->defaultSort('updated_at')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEntitlementOverrides::route('/'),
            'view' => ViewEntitlementOverride::route('/{firmUuid}/{id}'),
        ];
    }
}
