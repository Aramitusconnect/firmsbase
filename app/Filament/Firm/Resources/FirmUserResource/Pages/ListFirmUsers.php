<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmUserResource\Pages;

use App\Filament\Firm\Resources\FirmUserResource;
use App\Filament\Firm\Resources\FirmUserResource\Actions\InviteFirmUserAction;
use App\Filament\Firm\Resources\FirmUserResource\Widgets\TeamSeatUsageWidget;
use Filament\Resources\Pages\ListRecords;

/**
 * ListFirmUsers — "+ Invite Team Member" lives here as
 * InviteFirmUserAction (a custom header Action), NOT a
 * CreateAction/CreateRecord page — FirmUserResource declares no
 * 'create' route at all, see that class's own docblock.
 *
 * TeamSeatUsageWidget (Firm Feature Manifest §12 flat per-firm seat
 * model) renders above the table — see that widget's own docblock for
 * why the informational-widget-plus-clickable-action shape was chosen
 * over hiding the invite action outright.
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

    protected function getHeaderWidgets(): array
    {
        return [
            TeamSeatUsageWidget::class,
        ];
    }
}
