<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryImportBatchResource\Pages;

use App\Filament\Actions\Platform\ApplyImportBatchAction;
use App\Filament\Actions\Platform\ConfirmImportSourceRightsAction;
use App\Filament\Resources\DirectoryImportBatchResource;
use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Models\DirectoryImportBatch;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ViewDirectoryImportBatch extends ViewRecord
{
    protected static string $resource = DirectoryImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConfirmImportSourceRightsAction::make(),
            ApplyImportBatchAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Batch')
                ->columns(2)
                ->schema([
                    TextEntry::make('original_filename')->label('File'),
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
}
