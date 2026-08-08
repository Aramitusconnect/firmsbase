<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmUserResource\Pages;

use App\Filament\Firm\Resources\FirmUserResource;
use App\Filament\Firm\Resources\FirmUserResource\Actions\InviteFirmUserAction;
use Filament\Resources\Pages\ListRecords;

/**
 * ListFirmUsers — "+ Invite Team Member" lives here as
 * InviteFirmUserAction (a custom header Action), NOT a
 * CreateAction/CreateRecord page — FirmUserResource declares no
 * 'create' route at all, see that class's own docblock.
 */
class ListFirmUsers extends ListRecords
{
    protected static string $resource = FirmUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InviteFirmUserAction::make(),
        ];
    }
}
