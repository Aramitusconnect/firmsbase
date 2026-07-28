<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlaidItemOversightResource\Pages;

use App\Filament\Resources\PlaidItemOversightResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPlaidItemOversight — no header actions: administrative oversight
 * only, never a data-entry form (mirrors `ListConnections`' own ruling).
 */
class ListPlaidItemOversight extends ListRecords
{
    protected static string $resource = PlaidItemOversightResource::class;
}
