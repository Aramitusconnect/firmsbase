<?php

declare(strict_types=1);

namespace App\Filament\Resources\EntitlementOverrideResource\Pages;

use App\Filament\Resources\EntitlementOverrideResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListEntitlementOverrides — the Set Override header action is
 * registered on the Resource's table() itself (see
 * EntitlementOverrideResource::table()'s ->headerActions()), not here.
 */
class ListEntitlementOverrides extends ListRecords
{
    protected static string $resource = EntitlementOverrideResource::class;
}
