<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryCorrectionRequestResource\Pages;

use App\Filament\Resources\DirectoryCorrectionRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListDirectoryCorrectionRequests extends ListRecords
{
    protected static string $resource = DirectoryCorrectionRequestResource::class;
}
