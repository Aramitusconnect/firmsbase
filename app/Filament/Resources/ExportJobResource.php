<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ExportJobStatus;
use App\Enums\ExportType;
use App\Filament\Resources\ExportJobResource\Pages\ListExportJobs;
use App\Filament\Resources\ExportJobResource\Pages\ViewExportJob;
use App\Models\ExportJob;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformDataExportGovernanceDirectoryService;
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

/**
 * ExportJobResource — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations, Governance, Support, and Configuration"),
 * Governance category, Data Exports module. Cross-firm, READ-ONLY
 * List+View over `export_jobs` — no mutation is exposed:
 * markInProgress()/markCompleted()/markFailed() on ExportJobService
 * accept no actor parameter at all (system-only lifecycle transitions
 * with no attribution capability), unlike every other mutation this
 * phase exposes.
 *
 * No real file is ever produced by any export in this system — see
 * PlatformDataExportGovernanceDirectoryService's own docblock.
 *
 * FORCE RLS, firm-scoped only — queried exclusively via
 * PlatformDataExportGovernanceDirectoryService's per-firm-loop pattern.
 */
class ExportJobResource extends Resource
{
    protected static ?string $model = ExportJob::class;

    protected static ?string $slug = 'export-jobs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static ?string $navigationLabel = 'Export Jobs';

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

                return app(PlatformDataExportGovernanceDirectoryService::class)->listExportJobs($admin, [
                    'firm_uuid' => $filters['firm_uuid']['value'] ?? null,
                    'status' => $filters['status']['value'] ?? null,
                    'export_type' => $filters['export_type']['value'] ?? null,
                ])->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                SelectFilter::make('status')
                    ->options(collect(ExportJobStatus::cases())
                        ->mapWithKeys(fn (ExportJobStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                SelectFilter::make('export_type')
                    ->label('Export type')
                    ->options(collect(ExportType::cases())
                        ->mapWithKeys(fn (ExportType $type): array => [$type->value => Str::headline($type->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('export_type')->label('Export type')->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        ExportJobStatus::Completed->value => 'success',
                        ExportJobStatus::InProgress->value, ExportJobStatus::Requested->value => 'warning',
                        ExportJobStatus::Failed->value, ExportJobStatus::Blocked->value => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                IconColumn::make('legal_hold_checked')->label('Legal hold checked')->boolean(),
                IconColumn::make('retention_checked')->label('Retention checked')->boolean(),
                TextColumn::make('created_at')->label('Requested at')->dateTime(),
                TextColumn::make('completed_at')->label('Completed at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewExportJob::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No export jobs found')
            ->emptyStateDescription('No real file is ever produced by any export in this system — this is status/manifest visibility only.')
            ->defaultSort('created_at')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExportJobs::route('/'),
            'view' => ViewExportJob::route('/{firmUuid}/{id}'),
        ];
    }
}
