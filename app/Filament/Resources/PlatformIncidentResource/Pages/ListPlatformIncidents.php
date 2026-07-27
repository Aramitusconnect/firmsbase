<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformIncidentResource\Pages;

use App\Filament\Actions\Platform\OpenIncidentAction;
use App\Filament\Resources\PlatformIncidentResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPlatformIncidents — header action: OpenIncidentAction (the sole
 * way a new platform-wide incident is created from this console).
 */
class ListPlatformIncidents extends ListRecords
{
    protected static string $resource = PlatformIncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OpenIncidentAction::make(),
        ];
    }
}
