<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExportJobResource\Pages;

use App\Filament\Resources\ExportJobResource;
use Filament\Resources\Pages\ListRecords;

class ListExportJobs extends ListRecords
{
    protected static string $resource = ExportJobResource::class;
}
