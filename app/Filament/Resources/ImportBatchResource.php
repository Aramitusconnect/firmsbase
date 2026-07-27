<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Filament\Resources\ImportBatchResource\Pages\ListImportBatches;
use App\Filament\Resources\ImportBatchResource\Pages\ViewImportBatch;
use App\Models\Firm;
use App\Models\ImportBatch;
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
 * ImportBatchResource — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations, Governance, Support, and Configuration"),
 * Governance category, Data Exports module (import/migration direction —
 * data coming IN, a distinct direction from exports proper). Cross-firm,
 * READ-ONLY List+View over `import_batches` — no admin-facing mutation
 * is exposed; this is a firm-panel-driven workflow this console only
 * observes.
 *
 * FORCE RLS, firm-scoped only — queried exclusively via
 * PlatformDataExportGovernanceDirectoryService's per-firm-loop pattern.
 */
class ImportBatchResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static ?string $slug = 'import-batches';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    protected static ?string $navigationLabel = 'Imports';

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

                return app(PlatformDataExportGovernanceDirectoryService::class)->listImportBatches($admin, [
                    'firm_uuid' => $filters['firm_uuid']['value'] ?? null,
                    'status' => $filters['status']['value'] ?? null,
                    'entity_type' => $filters['entity_type']['value'] ?? null,
                ])->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                SelectFilter::make('status')
                    ->options(collect(ImportBatchStatus::cases())
                        ->mapWithKeys(fn (ImportBatchStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                SelectFilter::make('entity_type')
                    ->label('Entity type')
                    ->options(collect(ImportEntityType::cases())
                        ->mapWithKeys(fn (ImportEntityType $type): array => [$type->value => Str::headline($type->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('entity_type')->label('Entity type')->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                TextColumn::make('source_type')->label('Source')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        ImportBatchStatus::Applied->value => 'success',
                        ImportBatchStatus::Failed->value => 'danger',
                        ImportBatchStatus::Cancelled->value, ImportBatchStatus::RolledBack->value => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                TextColumn::make('created_at')->label('Created at')->dateTime(),
                TextColumn::make('applied_at')->label('Applied at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewImportBatch::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No import batches found')
            ->defaultSort('created_at')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportBatches::route('/'),
            'view' => ViewImportBatch::route('/{firmUuid}/{id}'),
        ];
    }
}
