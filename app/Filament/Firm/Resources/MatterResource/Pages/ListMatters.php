<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\Pages;

use App\Filament\Firm\Resources\MatterResource;
use App\Filament\Firm\Resources\MatterResource\Actions\AddMatterAction;
use Filament\Resources\Pages\ListRecords;

/**
 * ListMatters — Checkpoint 4 ("Plaid financial evidence add-on").
 * Searchable matter list (client name, stage — see
 * MatterResource::table()).
 *
 * Tier 3 addition: hosts the "+ Add Matter" header action
 * (AddMatterAction, wired to the new MatterCreationService — Firm
 * Feature Manifest §2's confirmed gap). Still no Filament-generic
 * `CreateAction`/`CreateRecord` route — MatterResource declares no
 * 'create' page at all (see MatterResource::getPages()), matching
 * ListFirmIntegrations'/ListClients' "no ad-hoc Create form" discipline
 * — this Action never binds a form directly to the `Matter` model.
 */
class ListMatters extends ListRecords
{
    protected static string $resource = MatterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AddMatterAction::make(),
        ];
    }
}
