<?php

declare(strict_types=1);

namespace App\Filament\Resources\FirmResource\Pages;

use App\Filament\Actions\Platform\ExportFirmInventoryCsvAction;
use App\Filament\Actions\Platform\ProvisionFirmAction;
use App\Filament\Resources\FirmResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListFirms — no generic Filament CreateAction (this remains an
 * administrative oversight view, never a bare data-entry form bound
 * directly to the Firm model — see FirmResource's own docblock). One
 * header action registered is ProvisionFirmAction, a reviewed
 * multi-step wizard that calls FirmProvisioningService end-to-end
 * rather than saving a partial Firm row. The other, ExportFirmInventoryCsvAction
 * (CORE SuperAdmin mission, Phase 5, section 61), is a bounded,
 * synchronous CSV export mirroring the codebase's established
 * League\Csv/streamDownload pattern — see that action's own docblock.
 */
class ListFirms extends ListRecords
{
    protected static string $resource = FirmResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ProvisionFirmAction::make(),
            ExportFirmInventoryCsvAction::make(),
        ];
    }
}
