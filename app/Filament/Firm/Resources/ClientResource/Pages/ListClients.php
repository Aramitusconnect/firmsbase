<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\Pages;

use App\Filament\Firm\Resources\ClientResource;
use App\Filament\Firm\Resources\ClientResource\Actions\AddClientAction;
use Filament\Resources\Pages\ListRecords;

/**
 * ListClients — the product-required "+ Add Client" primary action
 * lives here as AddClientAction (a custom header Action), NOT a
 * CreateAction/CreateRecord page — see ClientResource's own docblock
 * and AddClientAction's own docblock for why.
 */
class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AddClientAction::make(),
        ];
    }
}
