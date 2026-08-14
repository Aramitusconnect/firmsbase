<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryImportBatchResource\Pages;

use App\Filament\Actions\Platform\ApplyImportBatchAction;
use App\Filament\Actions\Platform\ConfirmImportSourceRightsAction;
use App\Filament\Actions\Platform\DownloadImportBatchErrorCsvAction;
use App\Filament\Resources\DirectoryImportBatchResource;
use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Services\MarketplaceImportApplyService;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * ViewDirectoryImportBatch — MyAttorney SuperAdmin console
 * professionalization mission (MYAT6). Adds a "Preview" section
 * surfacing MarketplaceImportApplyService::preview() — that method has
 * existed since Mission 2 checkpoint 11 (its own docblock is explicit
 * about the exact create/update/skip breakdown it computes) but was
 * never rendered anywhere before this mission, confirmed by this
 * mission's own discovery pass.
 */
class ViewDirectoryImportBatch extends ViewRecord
{
    protected static string $resource = DirectoryImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConfirmImportSourceRightsAction::make(),
            ApplyImportBatchAction::make(),
            DownloadImportBatchErrorCsvAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Batch')
                ->columns(2)
                ->schema([
                    TextEntry::make('original_filename')->label('File'),
                    TextEntry::make('createdBy.name')->label('Uploaded By')->placeholder('—'),
                    TextEntry::make('status')->badge()->formatStateUsing(fn (DirectoryImportBatchStatus $state) => Str::headline($state->value)),
                    IconEntry::make('source_rights_confirmed')->label('Source Rights Confirmed')->boolean(),
                    TextEntry::make('total_rows')->label('Total Rows'),
                    TextEntry::make('valid_rows')->label('Valid'),
                    TextEntry::make('invalid_rows')->label('Invalid'),
                    TextEntry::make('duplicate_rows')->label('Duplicates'),
                    TextEntry::make('applied_rows')->label('Applied'),
                    TextEntry::make('skipped_rows')->label('Skipped'),
                    TextEntry::make('created_at')->label('Uploaded')->dateTime(),
                ]),
            Section::make('Preview')
                ->description('What Apply would do if run right now, based on each row\'s current status.')
                ->columns(3)
                ->schema([
                    TextEntry::make('preview_creatable')->label('Would Create')->state(fn (DirectoryImportBatch $record) => $this->preview($record)['creatable']),
                    TextEntry::make('preview_updatable')->label('Would Update')->state(fn (DirectoryImportBatch $record) => $this->preview($record)['updatable']),
                    TextEntry::make('preview_invalid')->label('Invalid (skipped)')->state(fn (DirectoryImportBatch $record) => $this->preview($record)['invalid']),
                    TextEntry::make('preview_skipped_claimed')->label('Skipped — Already Claimed')->state(fn (DirectoryImportBatch $record) => $this->preview($record)['skipped_already_claimed']),
                    TextEntry::make('preview_skipped_verified')->label('Skipped — More Recently Verified')->state(fn (DirectoryImportBatch $record) => $this->preview($record)['skipped_more_recently_verified']),
                ])
                ->visible(fn (DirectoryImportBatch $record): bool => in_array($record->status, [
                    DirectoryImportBatchStatus::Validated,
                    DirectoryImportBatchStatus::Previewed,
                    DirectoryImportBatchStatus::SourceApprovalRequired,
                ], true))
                ->collapsible(),
            Section::make('Rows (first 50)')
                ->schema([
                    RepeatableEntry::make('rows')
                        ->hiddenLabel()
                        ->state(fn (DirectoryImportBatch $record): array => $record->rows()->orderBy('row_number')->limit(50)->get()
                            ->map(fn ($r) => [
                                'row_number' => $r->row_number,
                                'name' => $r->mapped_data['display_name'] ?? $r->raw_data['display_name'] ?? '—',
                                'status' => Str::headline($r->status->value),
                                'errors' => $r->errors !== null ? implode('; ', $r->errors) : '—',
                            ])
                            ->all())
                        ->schema([
                            TextEntry::make('row_number')->label('#'),
                            TextEntry::make('name')->label('Display Name'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('errors')->label('Errors'),
                        ])
                        ->columns(4),
                ])
                ->collapsed(),
        ]);
    }

    /**
     * @return array{creatable: int, updatable: int, skipped_already_claimed: int, skipped_more_recently_verified: int, invalid: int}
     */
    private function preview(DirectoryImportBatch $record): array
    {
        return app(MarketplaceImportApplyService::class)->preview($record);
    }
}
