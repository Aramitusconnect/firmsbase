<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConnectionResource\Pages;

use App\Filament\Resources\ConnectionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListConnections — no header actions: this is an administrative
 * oversight view over connections created through each firm's own
 * normal OAuth/connect flow, not a data-entry form. No CreateAction,
 * mirroring FirmResource/FirmUserResource's own "no Create/Edit forms"
 * ruling.
 */
class ListConnections extends ListRecords
{
    protected static string $resource = ConnectionResource::class;
}
