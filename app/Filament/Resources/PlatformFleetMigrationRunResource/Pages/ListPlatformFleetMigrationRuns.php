<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformFleetMigrationRunResource\Pages;

use App\Filament\Actions\Platform\CreateFleetMigrationRunAction;
use App\Filament\Resources\PlatformFleetMigrationRunResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPlatformFleetMigrationRuns — header action:
 * CreateFleetMigrationRunAction, the sole way a new run is created
 * from this console.
 */
class ListPlatformFleetMigrationRuns extends ListRecords
{
    protected static string $resource = PlatformFleetMigrationRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateFleetMigrationRunAction::make(),
        ];
    }
}
