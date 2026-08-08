<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\CommunicationConsentResource\Pages;

use App\Filament\Firm\Resources\CommunicationConsentResource;
use App\Filament\Firm\Resources\CommunicationConsentResource\Actions\CaptureConsentAction;
use Filament\Resources\Pages\ListRecords;

/**
 * ListCommunicationConsents — the firm-wide "Record Consent" primary
 * action lives here as CaptureConsentAction (a custom header Action),
 * NOT a CreateAction/CreateRecord page — see CommunicationConsentResource's
 * own docblock for why.
 */
class ListCommunicationConsents extends ListRecords
{
    protected static string $resource = CommunicationConsentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CaptureConsentAction::make(),
        ];
    }
}
