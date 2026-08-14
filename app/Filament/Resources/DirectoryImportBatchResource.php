<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\Platform\ApplyImportBatchAction;
use App\Filament\Actions\Platform\ConfirmImportSourceRightsAction;
use App\Filament\Actions\Platform\DownloadImportBatchErrorCsvAction;
use App\Filament\Resources\DirectoryImportBatchResource\Pages\ListDirectoryImportBatches;
use App\Filament\Resources\DirectoryImportBatchResource\Pages\ViewDirectoryImportBatch;
use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
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
 * DirectoryImportBatchResource — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11 (sections 53-55). Cross-firm List+View
 * oversight over `directory_import_batches` — a deliberately parallel
 * table to the Firm-scoped import_batches (see that table's own
 * migration docblock for why).
 */
class DirectoryImportBatchResource extends Resource
{
    protected static ?string $model = DirectoryImportBatch::class;

    protected static ?string $slug = 'directory-import-batches';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Import Batches';

    protected static string|\UnitEnum|null $navigationGroup = 'MyAttorney Marketplace';

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

        return app(PlatformStaffAccessPolicyService::class)->canManageMarketplaceGovernance($admin)->allowed;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_filename')->label('File')->searchable(),
                TextColumn::make('source')->label('Source')->state(fn () => 'CSV Upload')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')->label('Uploaded By')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (DirectoryImportBatchStatus $state): string => Str::headline($state->value))
                    ->color(fn (DirectoryImportBatchStatus $state): string => match ($state) {
                        DirectoryImportBatchStatus::Applied => 'success',
                        DirectoryImportBatchStatus::Cancelled => 'danger',
                        DirectoryImportBatchStatus::SourceApprovalRequired => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                IconColumn::make('source_rights_confirmed')->label('Source Rights Confirmed')->boolean(),
                TextColumn::make('total_rows')->label('Total'),
                TextColumn::make('valid_rows')->label('Valid'),
                TextColumn::make('invalid_rows')->label('Invalid')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('duplicate_rows')->label('Duplicates')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('applied_rows')->label('Applied')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('skipped_rows')->label('Skipped')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Uploaded')->dateTime()->sortable(),
                TextColumn::make('updated_at')
                    ->label('Completed')
                    ->dateTime()
                    ->placeholder('—')
                    ->state(fn (DirectoryImportBatch $record) => $record->status === DirectoryImportBatchStatus::Applied ? $record->updated_at : null)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(DirectoryImportBatchStatus::cases())->mapWithKeys(fn (DirectoryImportBatchStatus $s) => [$s->value => Str::headline($s->value)])->all()),
            ])
            ->recordActions([
                ConfirmImportSourceRightsAction::make(),
                ApplyImportBatchAction::make(),
                DownloadImportBatchErrorCsvAction::make(),
            ])
            ->emptyStateHeading('No import batches yet')
            ->emptyStateDescription('Import directory data to get started.')
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDirectoryImportBatches::route('/'),
            'view' => ViewDirectoryImportBatch::route('/{record}'),
        ];
    }
}
