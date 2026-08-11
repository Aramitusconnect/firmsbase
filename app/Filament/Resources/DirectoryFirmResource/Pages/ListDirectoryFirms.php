<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryFirmResource\Pages;

use App\Filament\Resources\DirectoryFirmResource;
use Filament\Resources\Pages\ListRecords;

class ListDirectoryFirms extends ListRecords
{
    protected static string $resource = DirectoryFirmResource::class;
}
