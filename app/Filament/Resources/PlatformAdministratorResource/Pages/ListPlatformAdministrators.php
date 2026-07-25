<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformAdministratorResource\Pages;

use App\Filament\Resources\PlatformAdministratorResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPlatformAdministrators — no header actions: no Create form (see
 * PlatformAdministratorResource's own docblock for why). Mutations
 * happen on the View page, per-record, not here.
 */
class ListPlatformAdministrators extends ListRecords
{
    protected static string $resource = PlatformAdministratorResource::class;
}
