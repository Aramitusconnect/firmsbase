<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProviderKillSwitchResource\Pages;

use App\Filament\Resources\ProviderKillSwitchResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListProviderKillSwitches — mutations happen entirely via the
 * dedicated `Action` classes wired into `ProviderKillSwitchResource::table()`
 * (createAction()/toggleAction()) — no generic Filament CreateRecord/
 * EditRecord page exists for this resource.
 */
class ListProviderKillSwitches extends ListRecords
{
    protected static string $resource = ProviderKillSwitchResource::class;
}
