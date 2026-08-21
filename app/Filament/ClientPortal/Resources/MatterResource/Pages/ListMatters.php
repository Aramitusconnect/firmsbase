<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Resources\MatterResource\Pages;

use App\Filament\ClientPortal\Resources\MatterResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListMatters (Client Portal) — Mission 4 (Client Portal Activation),
 * finding 4.3. No header actions — a client cannot create a matter.
 * Row scoping is MatterResource::getEloquentQuery()'s job (a UX-layer
 * filter only, never the real boundary — see that class's own
 * docblock).
 */
class ListMatters extends ListRecords
{
    protected static string $resource = MatterResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
