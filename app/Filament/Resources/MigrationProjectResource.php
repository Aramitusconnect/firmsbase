<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\MigrationProjectStatus;
use App\Filament\Resources\MigrationProjectResource\Pages\ListMigrationProjects;
use App\Filament\Resources\MigrationProjectResource\Pages\ViewMigrationProject;
use App\Models\Firm;
use App\Models\MigrationProject;
use App\Models\PlatformAdmin;
use App\Services\PlatformDataExportGovernanceDirectoryService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * MigrationProjectResource — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations, Governance, Support, and Configuration"),
 * Governance category, Data Exports module (import/migration direction).
 * Cross-firm, READ-ONLY List+View over `migration_projects`. Source
 * types are guides/labels only — no real external API call is ever made
 * (see MigrationProject's own docblock).
 *
 * FORCE RLS, firm-scoped only — queried exclusively via
 * PlatformDataExportGovernanceDirectoryService's per-firm-loop pattern.
 */
class MigrationProjectResource extends Resource
{
    protected static ?string $model = MigrationProject::class;

    protected static ?string $slug = 'migration-projects';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Migration Projects';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessGovernance($admin)->allowed;
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

                return app(PlatformDataExportGovernanceDirectoryService::class)->listMigrationProjects($admin, [
                    'firm_uuid' => $filters['firm_uuid']['value'] ?? null,
                    'status' => $filters['status']['value'] ?? null,
                ])->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                SelectFilter::make('status')
                    ->options(collect(MigrationProjectStatus::cases())
                        ->mapWithKeys(fn (MigrationProjectStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('source_type')->label('Source')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        MigrationProjectStatus::Completed->value => 'success',
                        MigrationProjectStatus::Failed->value => 'danger',
                        MigrationProjectStatus::Cancelled->value => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                TextColumn::make('created_at')->label('Created at')->dateTime(),
                TextColumn::make('completed_at')->label('Completed at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewMigrationProject::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No migration projects found')
            ->defaultSort('created_at')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMigrationProjects::route('/'),
            'view' => ViewMigrationProject::route('/{firmUuid}/{id}'),
        ];
    }
}
