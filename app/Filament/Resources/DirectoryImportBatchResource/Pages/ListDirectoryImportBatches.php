<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryImportBatchResource\Pages;

use App\Filament\Actions\Platform\UploadDirectoryImportBatchAction;
use App\Filament\Resources\DirectoryImportBatchResource;
use Filament\Resources\Pages\ListRecords;

class ListDirectoryImportBatches extends ListRecords
{
    protected static string $resource = DirectoryImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UploadDirectoryImportBatchAction::make(),
        ];
    }
}
