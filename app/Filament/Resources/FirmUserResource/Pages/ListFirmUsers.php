<?php

declare(strict_types=1);

namespace App\Filament\Resources\FirmUserResource\Pages;

use App\Filament\Actions\Platform\InviteFirmUserAction;
use App\Filament\Resources\FirmUserResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListFirmUsers — CORE SuperAdmin mission, section 22: one header
 * action, InviteFirmUserAction (thin UI over the existing canonical
 * FirmUserInvitationService::invite() — see that action's own
 * docblock for why this is an Action rather than a Create page).
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
