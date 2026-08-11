<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryImportBatchResource\Pages;

use App\Filament\Resources\DirectoryImportBatchResource;
use Filament\Resources\Pages\ListRecords;

class ListDirectoryImportBatches extends ListRecords
{
    protected static string $resource = DirectoryImportBatchResource::class;
}
