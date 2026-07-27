<?php

declare(strict_types=1);

namespace App\Filament\Resources\MigrationProjectResource\Pages;

use App\Filament\Resources\MigrationProjectResource;
use Filament\Resources\Pages\ListRecords;

class ListMigrationProjects extends ListRecords
{
    protected static string $resource = MigrationProjectResource::class;
}
