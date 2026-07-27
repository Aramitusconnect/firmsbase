<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\FleetMigrationRunStatus;
use App\Filament\Pages\PlatformFleetMigrationRunDetailPage;
use App\Filament\Resources\PlatformFleetMigrationRunResource\Pages\ListPlatformFleetMigrationRuns;
use App\Models\FleetMigrationRun;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlatformFleetMigrationRunResource — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations"). `fleet_migration_runs` is
 * Global/no-RLS (not firm-owned — a single run spans many firms,
 * confirmed in the repo's own live RLS coverage inventory), so
 * this Resource's List page uses an ordinary Eloquent ->query() with
 * no context handling. Per-run per-firm instance-status drill-down
 * (FORCE RLS — needs the per-firm-loop pattern) lives on the separate
 * PlatformFleetMigrationRunDetailPage, not a standard Resource View
 * page — this Resource is List-only, mirroring
 * PlatformFirmIntegrationsPage's own "custom Page for the drill-down,
 * not a Resource ViewRecord" precedent (needed here to combine a
 * details section AND an embedded per-instance table on the same
 * page, which no existing ViewRecord+HasTable combination is proven
 * in this codebase).
 *
 * SIMULATED ONLY, throughout: no real infrastructure or firm data is
 * ever touched by any action reachable from this Resource — see
 * FleetMigrationOrchestrationService's own docblock. Labeled as a
 * rehearsal/planning tool everywhere in this UI, never implying a real
 * rollout occurs.
 */
class PlatformFleetMigrationRunResource extends Resource
{
    protected static ?string $model = FleetMigrationRun::class;

    protected static ?string $slug = 'platform-fleet-migration-runs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?string $navigationLabel = 'Fleet Migrations';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 87;

    protected static ?string $recordTitleAttribute = 'migration_identifier';

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

        return app(PlatformStaffAccessPolicyService::class)->canAccessOperations($admin)->allowed;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => FleetMigrationRun::query())
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(FleetMigrationRunStatus::cases())
                        ->mapWithKeys(fn (FleetMigrationRunStatus $s): array => [$s->value => Str::headline($s->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('migration_identifier')->label('Migration')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (FleetMigrationRunStatus $state): string => Str::headline($state->value))
                    ->color(fn (FleetMigrationRunStatus $state): string => match ($state) {
                        FleetMigrationRunStatus::Completed => 'success',
                        FleetMigrationRunStatus::InProgress, FleetMigrationRunStatus::Pending => 'warning',
                        FleetMigrationRunStatus::Halted, FleetMigrationRunStatus::RolledBack => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('started_at')->label('Started at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('completed_at')->label('Completed at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('halted_reason')->label('Halted reason')->placeholder('—')->limit(40),
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (FleetMigrationRun $record): string => PlatformFleetMigrationRunDetailPage::getUrl(['runUuid' => $record->uuid])),
            ])
            ->recordUrl(fn (FleetMigrationRun $record): string => PlatformFleetMigrationRunDetailPage::getUrl(['runUuid' => $record->uuid]))
            ->emptyStateHeading('No fleet migration runs yet')
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformFleetMigrationRuns::route('/'),
        ];
    }
}
